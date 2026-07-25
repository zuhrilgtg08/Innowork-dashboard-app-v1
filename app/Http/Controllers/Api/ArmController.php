<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArmStatus;
use Illuminate\Http\JsonResponse;

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
}
