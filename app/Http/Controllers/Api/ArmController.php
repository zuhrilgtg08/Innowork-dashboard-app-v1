<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArmStatus;
use App\Models\SystemLog;
use App\Models\TargetZonePreset;
use App\Services\ArmMqttService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Robotic arm state, zones, and command dispatch (Fase 3).
 */
class ArmController extends Controller
{
    public function __construct(private ArmMqttService $armMqtt) {}

    public function show(): JsonResponse
    {
        $arm = ArmStatus::current();

        return response()->json([
            'state' => $arm->state,
            'state_label' => $arm->stateLabel(),
            'detail' => $arm->detail,
            'last_command' => $arm->last_command,
            'telemetry' => $arm->telemetry,
            'reported_at' => optional($arm->reported_at)->toIso8601String(),
        ]);
    }

    /**
     * List the target-zone presets a client can command the arm to move to.
     * Only category-bound presets are selectable — "default"/"return" are
     * internal fallbacks resolved automatically by ArmMqttService, not zones
     * an operator picks by hand.
     */
    public function zones(): JsonResponse
    {
        $zones = TargetZonePreset::whereNotNull('category')
            ->orderBy('category')
            ->get()
            ->map(fn (TargetZonePreset $preset) => [
                'slug' => $preset->slug,
                'label' => $preset->label,
                'joint_angles' => $preset->joint_angles,
                'selectable' => true,
            ]);

        return response()->json(['zones' => $zones]);
    }

    /**
     * Publish a movement command for a product category to the arm.
     *
     * This actuates physical hardware, so it is deliberately stricter than the
     * read endpoints: it needs *write* access on the "Live Camera" module (the
     * line-control surface — viewers only hold read there), it is rate limited
     * at the route, and every accepted command is written to the system log so
     * a movement can be traced back to the account that ordered it.
     *
     * Mobile never publishes to MQTT itself: resolving a category to joint
     * angles lives in ArmMqttService/TargetZonePreset, so the backend stays the
     * only publisher on "arm/command".
     */
    public function command(Request $request, ArmMqttService $arm): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'context' => ['nullable', 'array'],
        ]);

        $preset = TargetZonePreset::forCategory($data['category']);

        if ($preset === null) {
            // No preset matched this category and there is no "default"
            // fallback either — bad/unconfigured input, not a transient
            // broker problem, so this is a 422 the client can act on.
            return response()->json([
                'message' => 'Kategori ini tidak memiliki zona target yang dikonfigurasi.',
            ], 422);
        }

        $context = $data['context'] ?? [];
        // Stamp provenance so the ESP32 log and the audit trail agree on who
        // ordered the movement. Core keys still win over caller-supplied context.
        $context['source'] = 'mobile';
        $context['issued_by'] = $request->user()->id;

        // Build the payload once: it is both what gets published to MQTT and
        // what gets echoed back in the response, so preset resolution never
        // needs to run twice.
        $payload = array_merge($context, [
            'category' => $data['category'],
            'zone' => $preset->slug,
            'joint_angles' => $preset->joint_angles,
            'issued_at' => now()->toIso8601String(),
        ]);

        if (! $arm->publishPayload($payload)) {
            // Preset resolution already succeeded above, so a failure here is
            // the MQTT broker being unreachable — transient, worth retrying.
            return response()->json([
                'message' => 'Broker MQTT sedang offline. Coba lagi nanti.',
            ], 503);
        }

        SystemLog::create([
            'level' => 'info',
            'source' => 'arm',
            'message' => "Arm command '{$data['category']}' dikirim dari mobile oleh {$request->user()->name}.",
            'context' => [
                'category' => $data['category'],
                'issued_by' => $request->user()->id,
                'source' => 'mobile',
            ],
            'logged_at' => now(),
        ]);

        return response()->json([
            'message' => 'Command dikirim.',
            'command' => [
                'category' => $data['category'],
                'zone' => $payload['zone'],
                'joint_angles' => $payload['joint_angles'],
                'issued_at' => $payload['issued_at'],
            ],
        ]);
    }
}
