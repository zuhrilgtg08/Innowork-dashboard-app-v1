<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesJson;
use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Services\ConveyorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Conveyor line monitoring + control for the mobile app.
 *
 * Distinct from {@see ConveyorController}, which is the machine-facing *ingest*
 * endpoint the ml-service posts anomalies to (HMAC-signed). This one is the
 * human-facing read/command surface behind Sanctum.
 *
 * Anomalies are not their own table — {@see ConveyorService::raiseAlert()}
 * records them as `SystemLog` rows with `source = 'conveyor'` and the event
 * type in `context.event`. Reading them back therefore means querying
 * SystemLog, not a dedicated model.
 *
 * Gated on the `Live Camera` module rather than a new one: the conveyor is line
 * equipment shown alongside the arm, and `RolePermission::MODULES` has no
 * `Conveyor` entry (same reasoning as the arm endpoints — see CLAUDE.md).
 */
class ConveyorMonitorController extends Controller
{
    use PaginatesJson;

    /** How far back the summary counters look. */
    private const SUMMARY_WINDOW_HOURS = 24;

    /**
     * Current line health: broker reachability, the most recent anomaly, and
     * how many of each event type fired in the last 24 hours.
     */
    public function status(ConveyorService $conveyor): JsonResponse
    {
        $since = now()->subHours(self::SUMMARY_WINDOW_HOURS);

        $latest = SystemLog::where('source', 'conveyor')
            ->latest('logged_at')
            ->first();

        $counts = [];
        foreach (ConveyorService::EVENTS as $event) {
            $counts[$event] = SystemLog::where('source', 'conveyor')
                ->where('logged_at', '>=', $since)
                ->where('context->event', $event)
                ->count();
        }

        return response()->json([
            'data' => [
                'broker_connected' => $conveyor->isConnected(),
                'commands' => ConveyorService::COMMANDS,
                'events' => ConveyorService::EVENTS,
                'window_hours' => self::SUMMARY_WINDOW_HOURS,
                'counts' => $counts,
                'total_alerts' => array_sum($counts),
                'latest_alert' => $latest ? $this->payload($latest) : null,
            ],
        ]);
    }

    /**
     * Anomaly history, newest first.
     */
    public function alerts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event' => ['sometimes', 'string', Rule::in(ConveyorService::EVENTS)],
            'per_page' => $this->perPageRules(),
        ]);

        $alerts = SystemLog::where('source', 'conveyor')
            ->when(
                $validated['event'] ?? null,
                fn ($q, $event) => $q->where('context->event', $event),
            )
            ->latest('logged_at')
            ->paginate($this->perPage($validated));

        return $this->paginated($alerts, fn (SystemLog $log) => $this->payload($log));
    }

    /**
     * Send a control command to the line (start / stop / reverse / speed).
     *
     * `ConveyorService::command()` is best-effort and returns false for an
     * offline broker; that becomes a `503` here so the app can offer a retry
     * rather than silently pretending the line obeyed.
     */
    public function command(Request $request, ConveyorService $conveyor): JsonResponse
    {
        $validated = $request->validate([
            'command' => ['required', 'string', Rule::in(ConveyorService::COMMANDS)],
            'speed_rpm' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'line' => ['nullable', 'string', 'max:50'],
        ]);

        $context = array_filter([
            'speed_rpm' => $validated['speed_rpm'] ?? null,
            'line' => $validated['line'] ?? null,
        ], fn ($v) => $v !== null);

        if (! $conveyor->command($validated['command'], $context)) {
            return response()->json([
                'message' => 'Broker MQTT sedang offline. Coba lagi nanti.',
            ], 503);
        }

        return response()->json([
            'message' => "Command \"{$validated['command']}\" dikirim ke conveyor.",
            'data' => [
                'command' => $validated['command'],
                'context' => $context,
                'issued_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SystemLog $log): array
    {
        $context = is_array($log->context) ? $log->context : [];

        return [
            'id' => $log->id,
            'level' => $log->level,
            'message' => $log->message,
            'event' => $context['event'] ?? null,
            'conveyor' => $context['conveyor'] ?? null,
            // The flow metrics, minus the keys already surfaced as own fields.
            'metrics' => array_diff_key($context, array_flip(['event', 'conveyor'])),
            'logged_at' => optional($log->logged_at)->toIso8601String(),
        ];
    }
}
