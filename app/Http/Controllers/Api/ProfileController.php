<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in user editing their *own* account from the mobile app (Fase 4).
 *
 * Deliberately separate from {@see UserController}, which is administration:
 * that one is gated on the `Users` module, and a role without it (operator,
 * viewer) must still be able to change their own name or password. Here the
 * token itself is the authorisation — there is no module check, and the target
 * is always `$request->user()`, never an id from the request body.
 *
 * Mirrors the Volt profile forms in resources/views/livewire/profile/*.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->payload($request->user())]);
    }

    /**
     * Update name/email. Changing the email invalidates a previous verification,
     * exactly as the web profile form does.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return response()->json([
            'message' => 'Profil diperbarui.',
            'user' => $this->payload($user),
        ]);
    }

    /**
     * Change the password. The current password is required — a stolen token
     * alone should not be enough to lock the real owner out of their account.
     *
     * All *other* tokens are revoked afterwards so a session that was open when
     * the password changed cannot keep going; the token making this request is
     * kept so the app does not log itself out mid-flow.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini tidak cocok.'],
            ]);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        $currentTokenId = $request->user()->currentAccessToken()?->id;

        if ($currentTokenId !== null) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        return response()->json(['message' => 'Password diperbarui.']);
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, is_active: bool, email_verified_at: ?string}
     */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => (bool) ($user->is_active ?? true),
            'email_verified_at' => optional($user->email_verified_at)->toIso8601String(),
        ];
    }
}
