<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Livewire\Dashboard;
use App\Models\Detection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Aggregated QC numbers for the mobile dashboard (Fase 4).
 *
 * Mirrors {@see Dashboard} — the browser dashboard and the phone
 * must not disagree about what "pass rate" means, so the range windows, the
 * status buckets and the throughput definition are reproduced here rather than
 * recomputed differently. When the Livewire numbers change, change these too.
 */
class StatsController extends Controller
{
    /**
     * Ranges the client may ask for, mapped to how far back they reach.
     * `today` is calendar-day (not a rolling 24h) to match the web dashboard.
     */
    private const RANGES = ['today', '7d', '30d'];

    public function dashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'string', 'in:'.implode(',', self::RANGES)],
        ]);

        $range = $validated['range'] ?? 'today';
        $base = fn () => $this->rangeQuery($range);

        $total = $base()->count();
        $passed = $base()->where('status', 'passed')->count();
        $unreadable = $base()->where('status', 'unreadable')->count();
        $defective = $base()->whereIn('status', ['damaged', 'scratched'])->count();
        $returned = $base()->whereIn('status', ['returned', 'recheck'])->count();

        // Throughput: items in the last 60 minutes, per minute. Deliberately
        // independent of `range` — it describes the line right now.
        $lastHour = Detection::where('detected_at', '>=', now()->subHour())->count();

        return response()->json([
            'range' => $range,
            'stats' => [
                'total' => $total,
                'passed' => $passed,
                'pass_rate' => $total > 0 ? round($passed / $total * 100, 1) : 0.0,
                'unreadable' => $unreadable,
                'defective' => $defective,
                'returned' => $returned,
                'throughput_per_minute' => round($lastHour / 60, 1),
                'active_cameras' => Detection::where('detected_at', '>=', now()->subDay())
                    ->whereNotNull('camera')
                    ->distinct()
                    ->count('camera'),
            ],
            'distribution' => $this->distribution($range, $total),
            'trend' => $this->trend($range),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Time-bounded detection query for a range key.
     */
    private function rangeQuery(string $range)
    {
        $query = Detection::query();

        return match ($range) {
            '7d' => $query->where('detected_at', '>=', now()->subDays(7)),
            '30d' => $query->where('detected_at', '>=', now()->subDays(30)),
            default => $query->whereDate('detected_at', today()),
        };
    }

    /**
     * Share of each {@see Detection::STATUSES} key within the range. Every
     * status is returned even at zero, so the client can render a stable set of
     * bars instead of one that changes shape as statuses come and go.
     *
     * @return array<int, array{key: string, label: string, color: string, count: int, pct: float}>
     */
    private function distribution(string $range, int $total): array
    {
        return collect(Detection::STATUSES)
            ->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'color' => $meta['color'],
                'count' => $count = $this->rangeQuery($range)->where('status', $key)->count(),
                'pct' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * Bucketed series for the dashboard chart: hourly for `today`, daily
     * otherwise. Buckets with no detections are emitted as zeros so the chart
     * keeps an even time axis instead of collapsing quiet periods.
     *
     * @return array<int, array{label: string, total: int, passed: int, failed: int}>
     */
    private function trend(string $range): array
    {
        [$buckets, $unit] = match ($range) {
            '7d' => [7, 'day'],
            '30d' => [30, 'day'],
            default => [24, 'hour'],
        };

        $rows = $this->rangeQuery($range)
            ->get(['status', 'detected_at'])
            ->groupBy(fn (Detection $d) => $unit === 'hour'
                ? Carbon::parse($d->detected_at)->format('H')
                : Carbon::parse($d->detected_at)->format('Y-m-d'));

        $series = [];

        for ($i = $buckets - 1; $i >= 0; $i--) {
            $moment = $unit === 'hour' ? today()->addHours($buckets - 1 - $i) : now()->subDays($i);
            $key = $unit === 'hour' ? $moment->format('H') : $moment->format('Y-m-d');
            $inBucket = $rows->get($key, collect());

            $series[] = [
                'label' => $unit === 'hour' ? $moment->format('H:i') : $moment->format('d M'),
                'total' => $inBucket->count(),
                'passed' => $inBucket->where('status', 'passed')->count(),
                'failed' => $inBucket->whereIn('status', Detection::FAILED_STATUSES)->count(),
            ];
        }

        return $series;
    }
}
