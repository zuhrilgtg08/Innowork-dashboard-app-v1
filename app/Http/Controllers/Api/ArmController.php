<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArmStatus;
use App\Models\TargetZonePreset;
use App\Services\ArmMqttService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
     * Return selectable target zones for the mobile arm-control picker.
     * System zones (default, return) are excluded because the mobile app
     * sends explicit category names, not slugs.
     */
    public function zones(): JsonResponse
    {
        $presets = TargetZonePreset::all()
            ->reject(fn (TargetZonePreset $p) => in_array($p->slug, [TargetZonePreset::DEFAULT_SLUG, TargetZonePreset::RETURN_SLUG], true))
            ->values()
            ->all();

        $zones = array_map(static function (TargetZonePreset $p): array {
            return [
                'slug' => $p->slug,
                'label' => $p->label,
                'joint_angles' => $p->joint_angles,
                'selectable' => true,
            ];
        }, $presets);

        return response()->json(compact('zones'));
    }

    /**
     * Dispatch an arm command for a product category.
     *
     * @response 501 Arm command feature is not configured on this server.
     * @response 422 No target-zone preset exists for the given category.
     * @response 503 MQTT broker is offline — command could not be delivered.
     */
    public function command(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => 'required|string|max:255',
            'context' => 'sometimes|array',
        ]);

        $category = $validated['category'];
        $context = $validated['context'] ?? [];

        if (empty(config('services.mqtt.host'))) {
            return response()->json([
                'message' => 'Fitur kirim command belum tersedia di server.',
            ], 501);
        }

        $preset = TargetZonePreset::forCategory($category);

        if (! $preset) {
            return response()->json([
                'message' => 'Kategori ini tidak memiliki zona target yang dikonfigurasi.',
                'category' => $category,
            ], 422);
        }

        $payload = [
            'category' => $category,
            'zone' => $preset->slug,
            'joint_angles' => $preset->joint_angles,
            'issued_at' => now()->toIso8601String(),
        ];

        if (! empty($context)) {
            $payload = array_merge($context, $payload);
        }

        $published = $this->armMqtt->publishPayload($payload);

        if (! $published) {
            Log::warning('Arm command: broker offline', ['category' => $category, 'zone' => $preset->slug]);

            return response()->json([
                'message' => 'Broker MQTT sedang offline. Coba lagi nanti.',
            ], 503);
        }

        Log::info('Arm command dispatched', ['category' => $category, 'zone' => $preset->slug]);

        return response()->json([
            'message' => 'Command sent successfully.',
            'command' => $payload,
        ], 200);
    }
}
