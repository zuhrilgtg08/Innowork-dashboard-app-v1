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
 * Last-known robotic arm state (idle/running/error) for the mobile dashboard.
 * The state is kept current by the mqtt:listen consumer from "arm/status".
 */
class ArmController extends Controller
{
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

        // `forCategory()` already falls back to the "default" preset, so a null
        // here means no presets are seeded at all — a server misconfiguration,
        // not bad input from the client. Reporting it as 422 would send the
        // operator hunting for a mistake they did not make.
        if (TargetZonePreset::forCategory($data['category']) === null) {
            return response()->json([
                'message' => 'Preset zona target belum tersedia di server. Jalankan seeder TargetZonePreset.',
            ], 503);
        }

        $context = $data['context'] ?? [];
        // Stamp provenance so the ESP32 log and the audit trail agree on who
        // ordered the movement. Core keys still win inside buildCommandPayload.
        $context['source'] = 'mobile';
        $context['issued_by'] = $request->user()->id;

        if (! $arm->publishCommand($data['category'], $context)) {
            // Preset resolution already succeeded above, so a failure here is
            // the MQTT broker being unreachable — transient, worth retrying.
            return response()->json([
                'message' => 'Gagal mengirim command: broker MQTT tidak terjangkau.',
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
            'category' => $data['category'],
        ]);
    }
}
