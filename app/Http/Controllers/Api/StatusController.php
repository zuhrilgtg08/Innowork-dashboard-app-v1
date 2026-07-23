<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ArmMqttService;
use Illuminate\Http\JsonResponse;

/**
 * Compact system status for the mobile dashboard: overall online/offline
 * (driven by MQTT broker connectivity) plus a couple of settings fields.
 */
class StatusController extends Controller
{
    public function show(ArmMqttService $mqtt): JsonResponse
    {
        $setting = Setting::current();
        $mqttConnected = $mqtt->isConnected();

        return response()->json([
            'status' => $mqttConnected ? 'online' : 'offline',
            'mqtt_connected' => $mqttConnected,
            'app_name' => $setting->app_name,
            'timezone' => $setting->timezone,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
