<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RobotCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manual/debug robot command bridge (H-1): VPS <-> ESP32 over HTTPS.
 *
 * The ESP32 always initiates outbound requests carrying the robot device
 * Bearer token (see EnsureRobotDeviceToken). Laravel never dials out to a
 * private ESP32 IP. Commands persist in `robot_commands` with the lifecycle
 * PENDING -> ACKNOWLEDGED -> EXECUTING -> COMPLETED / FAILED.
 *
 * External JSON keeps the exact simulator payload shape (x, y, G, R, Y with
 * one-hot color flags); the database stores lowercase columns internally.
 * No MQTT, no YOLO coupling, no workspace scaling in this PR.
 */
class RobotCommandController extends Controller
{
    /**
     * Create a manual/debug robot command (producer / simulator).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'G' => ['required', 'integer', 'in:0,1'],
            'R' => ['required', 'integer', 'in:0,1'],
            'Y' => ['required', 'integer', 'in:0,1'],
            'source' => ['nullable', 'string', 'max:64'],
        ]);

        $color = RobotCommand::colorForFlags((int) $data['G'], (int) $data['R'], (int) $data['Y']);

        if ($color === null) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_color_flags',
                'message' => 'G, R and Y must be one-hot: exactly one flag must be 1.',
            ], 422);
        }

        $command = RobotCommand::create([
            'uuid' => (string) Str::uuid(),
            'source' => $data['source'] ?? 'manual',
            'x' => $data['x'],
            'y' => $data['y'],
            'g' => (int) $data['G'] === 1,
            'r' => (int) $data['R'] === 1,
            'y_flag' => (int) $data['Y'] === 1,
            'color' => $color,
            'status' => 'PENDING',
        ]);

        return response()->json([
            'ok' => true,
            'command' => $command->fresh()->toDevicePayload(),
        ], 201);
    }

    /**
     * Return the oldest PENDING command for this device.
     *
     * The row is claimed atomically inside a transaction so two devices can
     * never be handed the same command. Claiming does not change the status:
     * the device still ACKs explicitly. A device that crashed before ACK can
     * re-fetch its own claimed command.
     */
    public function next(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => ['nullable', 'string', 'max:64'],
        ]);

        $device = $this->deviceId($request);

        $command = DB::transaction(function () use ($device) {
            $candidate = RobotCommand::where('status', 'PENDING')
                ->where(function ($query) use ($device) {
                    $query->whereNull('claimed_by')->orWhere('claimed_by', $device);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($candidate && $candidate->claimed_by === null) {
                $candidate->claimed_by = $device;
                $candidate->save();
            }

            return $candidate;
        });

        return response()->json([
            'ok' => true,
            'command' => $command?->toDevicePayload(),
        ]);
    }

    /**
     * Confirm the device received the command. Idempotent: a repeated ACK
     * returns success without touching timestamps or state.
     */
    public function ack(Request $request, RobotCommand $command): JsonResponse
    {
        $request->validate([
            'device_id' => ['nullable', 'string', 'max:64'],
        ]);

        if (! $command->canTransitionTo('ACKNOWLEDGED')) {
            return $this->invalidTransition($command, 'ACKNOWLEDGED');
        }

        if ($command->status === 'PENDING') {
            $command->status = 'ACKNOWLEDGED';
            $command->acknowledged_at ??= now();
            $command->claimed_by ??= $this->deviceId($request);
            $command->save();
        }

        return response()->json([
            'ok' => true,
            'command' => $command->fresh()->toDevicePayload(),
        ]);
    }

    /**
     * Advance execution state. Idempotent repeats of the current state
     * succeed without changing timestamps, and terminal states can never
     * move backwards (e.g. COMPLETED -> EXECUTING is rejected).
     */
    public function status(Request $request, RobotCommand $command): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:EXECUTING,COMPLETED,FAILED'],
            'error' => ['nullable', 'string', 'max:2000'],
            'device_id' => ['nullable', 'string', 'max:64'],
        ]);

        $target = $data['status'];

        if (! $command->canTransitionTo($target)) {
            return $this->invalidTransition($command, $target);
        }

        if ($command->status !== $target) {
            $command->status = $target;

            if ($target === 'EXECUTING') {
                $command->executing_at ??= now();
            } elseif ($target === 'COMPLETED') {
                $command->completed_at ??= now();
            } elseif ($target === 'FAILED') {
                $command->failed_at ??= now();
                $command->error_message ??= $data['error'] ?? null;
            }

            $command->claimed_by ??= $this->deviceId($request);
            $command->save();
        }

        return response()->json([
            'ok' => true,
            'command' => $command->fresh()->toDevicePayload(),
        ]);
    }

    /**
     * Queue summary for debugging. Counts are real database counts; no
     * fabricated device-online status is reported (there is no heartbeat
     * in this PR).
     */
    public function queueStatus(): JsonResponse
    {
        $counts = RobotCommand::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $latest = RobotCommand::latest('id')->first();

        return response()->json([
            'ok' => true,
            'pending' => (int) ($counts['PENDING'] ?? 0),
            'acknowledged' => (int) ($counts['ACKNOWLEDGED'] ?? 0),
            'executing' => (int) ($counts['EXECUTING'] ?? 0),
            'completed' => (int) ($counts['COMPLETED'] ?? 0),
            'failed' => (int) ($counts['FAILED'] ?? 0),
            'latest_command' => $latest?->toDevicePayload(),
        ]);
    }

    protected function deviceId(Request $request): string
    {
        $id = trim((string) $request->input('device_id', 'esp32'));

        return $id !== '' ? mb_substr($id, 0, 64) : 'esp32';
    }

    protected function invalidTransition(RobotCommand $command, string $target): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'invalid_transition',
            'message' => "Cannot transition robot command from {$command->status} to {$target}.",
            'current_status' => $command->status,
        ], 422);
    }
}
