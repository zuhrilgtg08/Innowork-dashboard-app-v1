<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications to the mobile app through Expo's push service.
 *
 * Expo is used rather than talking to FCM/APNs directly: the app registers an
 * `ExponentPushToken[...]` and Expo fans out to the right transport per device,
 * so the backend needs no per-platform credentials. (Production still needs
 * FCM/APNs keys uploaded to the *Expo project* — that is a build-time concern,
 * not something this class configures.)
 *
 * Every call is best-effort, mirroring {@see MlClient}: a broker/HTTP failure
 * must never turn a successful QC event into a 500. Failures are logged and
 * swallowed.
 */
class PushNotifier
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /** Expo rejects batches larger than 100 messages. */
    private const CHUNK_SIZE = 100;

    /**
     * Send one notification to every device belonging to $users.
     *
     * @param  iterable<User>|Collection<int, User>  $users
     * @param  array<string, mixed>  $data  payload the app reads on tap (e.g. a route to open)
     * @return int number of messages handed to Expo
     */
    public function notifyUsers(iterable $users, string $title, string $body, array $data = []): int
    {
        $userIds = collect($users)->pluck('id')->filter()->all();

        if ($userIds === []) {
            return 0;
        }

        $tokens = DeviceToken::whereIn('user_id', $userIds)
            ->pluck('token')
            ->filter(fn (string $t) => DeviceToken::looksLikeExpoToken($t))
            ->unique()
            ->values();

        return $this->send($tokens->all(), $title, $body, $data);
    }

    /**
     * Notify every active user whose role can read $module, per the permission
     * matrix. Used for line-wide events (a conveyor jam concerns whoever is
     * allowed to watch the line, not one specific account).
     *
     * @param  array<string, mixed>  $data
     */
    public function notifyModuleWatchers(string $module, string $title, string $body, array $data = []): int
    {
        $matrix = RolePermission::matrix();

        // 'f'/'w'/'r' all imply read access; '-' does not.
        $roles = collect($matrix)
            ->filter(fn (array $modules) => in_array($modules[$module] ?? '-', ['f', 'w', 'r'], true))
            ->keys()
            ->all();

        if ($roles === []) {
            return 0;
        }

        $users = User::whereIn('role', $roles)
            ->where('is_active', true)
            ->get();

        return $this->notifyUsers($users, $title, $body, $data);
    }

    /**
     * Post the messages to Expo in chunks. Tokens Expo reports as unregistered
     * are deleted so a reinstalled app does not accumulate dead rows.
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, mixed>  $data
     */
    private function send(array $tokens, string $title, string $body, array $data): int
    {
        if ($tokens === []) {
            return 0;
        }

        $sent = 0;

        foreach (array_chunk($tokens, self::CHUNK_SIZE) as $chunk) {
            $messages = array_map(fn (string $token) => [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'sound' => 'default',
                'priority' => 'high',
            ], $chunk);

            try {
                $response = Http::timeout(10)
                    ->acceptJson()
                    ->asJson()
                    ->post(self::ENDPOINT, $messages);

                if (! $response->successful()) {
                    Log::warning('Expo push send failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    continue;
                }

                $sent += count($chunk);
                $this->pruneDeadTokens($chunk, $response->json('data') ?? []);
            } catch (\Throwable $e) {
                Log::warning('Expo push transport error', ['error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * Expo returns one receipt per message, positionally matching the request.
     * A `DeviceNotRegistered` error means the app was uninstalled or the token
     * rotated — that row will never deliver again, so drop it.
     *
     * @param  array<int, string>  $tokens
     * @param  array<int, mixed>  $receipts
     */
    private function pruneDeadTokens(array $tokens, array $receipts): void
    {
        $dead = [];

        foreach ($receipts as $index => $receipt) {
            if (! is_array($receipt) || ($receipt['status'] ?? null) !== 'error') {
                continue;
            }

            if (($receipt['details']['error'] ?? null) === 'DeviceNotRegistered' && isset($tokens[$index])) {
                $dead[] = $tokens[$index];
            }
        }

        if ($dead !== []) {
            DeviceToken::whereIn('token', $dead)->delete();
        }
    }
}
