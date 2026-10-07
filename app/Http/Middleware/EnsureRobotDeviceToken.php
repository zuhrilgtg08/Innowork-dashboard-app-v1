<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the manual/debug robot bridge behind a dedicated device Bearer token.
 *
 * The ESP32 initiates outbound HTTPS requests to the VPS carrying
 * `Authorization: Bearer <ROBOT_DEVICE_TOKEN>`. The configured token is
 * never exposed in responses or logs. Middleware-only: no sessions, no MQTT.
 */
class EnsureRobotDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('services.robot.device_token');

        if ($configured === '') {
            return response()->json([
                'ok' => false,
                'error' => 'robot_device_auth_not_configured',
            ], 503);
        }

        $provided = (string) $request->bearerToken();

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'ok' => false,
                'error' => 'unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
