<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Expo push token registration for the mobile app.
 *
 * Not gated by `module:` — receiving notifications is not a privileged module
 * action, and a role that can read the line at all should be reachable when it
 * jams. The token is always bound to the caller's own account.
 */
class DeviceTokenController extends Controller
{
    /**
     * Register (or re-point) this device's Expo push token.
     *
     * Keyed on the token, not on (user, device): when a second operator signs
     * in on the same handset, Expo hands out the *same* token, and the alert
     * must follow whoever is signed in now. `updateOrCreate` therefore moves
     * the existing row to the current user instead of leaving a stale row that
     * would deliver to the previous operator as well.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', Rule::in(DeviceToken::PLATFORMS)],
        ]);

        if (! DeviceToken::looksLikeExpoToken($validated['token'])) {
            return response()->json([
                'message' => 'Token bukan Expo push token yang valid.',
            ], 422);
        }

        $device = DeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Perangkat terdaftar untuk notifikasi.',
            'data' => [
                'id' => $device->id,
                'platform' => $device->platform,
                'registered_at' => optional($device->last_used_at)->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Stop notifying this device — called on logout so the handset does not
     * keep receiving alerts for an account that signed out of it.
     *
     * Scoped to the caller's own tokens: knowing a token string must not let
     * one account silence another's device.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $deleted = DeviceToken::where('token', $validated['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'message' => $deleted > 0
                ? 'Perangkat dilepas dari notifikasi.'
                : 'Perangkat tidak terdaftar.',
        ]);
    }
}
