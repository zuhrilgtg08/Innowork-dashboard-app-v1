<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConveyorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ingest endpoint for conveyor off-flow anomalies detected by the ml-service
 * (flow.py). HMAC-signed like the camera ingest (verify.ml middleware). Turns a
 * detected jam/off_flow into a SystemLog + "conveyor/alert" MQTT broadcast.
 */
class ConveyorController extends Controller
{
    public function event(Request $request, ConveyorService $conveyor): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:50'],
            'conveyor' => ['nullable', 'string', 'max:50'],
            'camera' => ['nullable', 'string', 'max:50'],
            'metrics' => ['nullable', 'array'],
        ]);

        $metrics = $data['metrics'] ?? [];
        if (! empty($data['camera'])) {
            $metrics['camera'] = $data['camera'];
        }

        $logId = $conveyor->raiseAlert($data['event'], $data['conveyor'] ?? null, $metrics);

        return response()->json(['ok' => true, 'log_id' => $logId]);
    }
}
