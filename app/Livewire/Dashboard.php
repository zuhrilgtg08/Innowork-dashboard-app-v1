<?php

namespace App\Livewire;

use App\Models\Detection;
use App\Services\MlClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('layouts.app', ['title' => 'Dashboard'])]
class Dashboard extends Component
{
    #[Url]
    public string $range = 'today';

    #[Url]
    public string $statusFilter = '';

    /**
     * Human-readable labels for the range selector (also used in the export).
     */
    public const RANGE_LABELS = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ];

    /**
     * Build the time-bounded query for the selected range.
     */
    protected function rangeQuery()
    {
        $query = Detection::query();

        return match ($this->range) {
            '7d' => $query->where('detected_at', '>=', now()->subDays(7)),
            '30d' => $query->where('detected_at', '>=', now()->subDays(30)),
            default => $query->whereDate('detected_at', today()),
        };
    }

    /**
     * Detail rows for the report: real Detection records respecting the
     * currently selected range AND status filter.
     */
    protected function exportQuery()
    {
        return $this->rangeQuery()
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with('product')
            ->orderBy('detected_at', 'desc');
    }

    /**
     * Export the current dashboard view as a CSV download.
     *
     * Respects the selected range + status filter and streams real Detection
     * records only — no synthetic data. An empty result still downloads a
     * valid CSV with the summary section and column headers.
     */
    public function exportReport(): StreamedResponse
    {
        $range = array_key_exists($this->range, self::RANGE_LABELS) ? $this->range : 'today';
        $this->range = $range;

        $base = $this->rangeQuery();

        $total = (clone $base)->count();
        $passed = (clone $base)->where('status', 'passed')->count();
        $unreadable = (clone $base)->where('status', 'unreadable')->count();
        $defective = (clone $base)->whereIn('status', ['damaged', 'scratched'])->count();
        $returned = (clone $base)->whereIn('status', ['returned', 'recheck'])->count();
        $passRate = $total > 0 ? round($passed / $total * 100, 1) : 0;

        $lastHour = Detection::where('detected_at', '>=', now()->subHour())->count();
        $throughput = round($lastHour / 60, 1);

        $activeCameras = Detection::where('detected_at', '>=', now()->subDay())
            ->distinct()
            ->count('camera');

        $statusLabel = $this->statusFilter !== '' && isset(Detection::STATUSES[$this->statusFilter])
            ? Detection::STATUSES[$this->statusFilter]['label']
            : 'All';

        $summary = [
            ['SortVision Detection Report'],
            ['Generated At', now()->toDateTimeString()],
            ['Selected Range', self::RANGE_LABELS[$range]],
            ['Selected Status', $statusLabel],
            ['Total Detections', $total],
            ['Passed', $passed],
            ['Unreadable', $unreadable],
            ['Defective', $defective],
            ['Returned/Recheck', $returned],
            ['Pass Rate', $passRate.'%'],
            ['Throughput', $throughput.' /min'],
            ['Active Cameras', $activeCameras],
        ];

        $header = ['Code', 'Product', 'Camera', 'Line', 'Status', 'Confidence (%)', 'Detected At'];

        // Chunk the detail rows so large exports never exhaust PHP memory.
        $rows = $this->exportQuery();

        $filename = 'sortvision-report-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($summary, $header, $rows) {
            // UTF-8 BOM so spreadsheet apps detect the encoding.
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');

            foreach ($summary as $line) {
                fputcsv($out, $line);
            }
            // Blank separator between the summary and the detail table.
            fputcsv($out, []);
            fputcsv($out, $header);

            $rows->chunk(500, function ($detections) use ($out) {
                foreach ($detections as $d) {
                    fputcsv($out, [
                        $d->code,
                        $d->product?->name ?? '',
                        $d->camera ?? '',
                        $d->conveyor ?? '',
                        $d->statusLabel(),
                        number_format((float) $d->confidence, 1),
                        optional($d->detected_at)->toDateTimeString() ?? '',
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render()
    {
        $ml = app(MlClient::class);

        // Live runtime telemetry (cached so wire:poll never hammers the ML
        // service). Null summary = ML service offline: the view renders
        // honest offline states instead of fake statistics.
        $summary = Cache::remember('ml.stats.summary', now()->addSeconds(5),
            fn () => $ml->statsSummary());
        $latest = Cache::remember('ml.detections.latest', now()->addSeconds(3),
            fn () => $ml->detectionsLatest());
        $confidence = Cache::remember('ml.stats.confidence', now()->addSeconds(5),
            fn () => $ml->confidenceStats(120));
        $timeline = Cache::remember('ml.stats.timeline', now()->addSeconds(10),
            fn () => $ml->timelineStats(60, 60));

        $online = is_array($summary);
        $green = $online ? (int) ($summary['green'] ?? 0) : 0;
        $yellow = $online ? (int) ($summary['yellow'] ?? 0) : 0;
        $red = $online ? (int) ($summary['red'] ?? 0) : 0;
        $total = $online ? (int) ($summary['total'] ?? 0) : 0;

        $distribution = collect([
            ['label' => 'GREEN', 'color' => 'green', 'count' => $green],
            ['label' => 'YELLOW', 'color' => 'amber', 'count' => $yellow],
            ['label' => 'RED', 'color' => 'red', 'count' => $red],
        ])->map(fn ($d) => $d + [
            'pct' => $total > 0 ? round($d['count'] / $total * 100, 1) : 0,
        ])->values();

        $detections = ($latest['detections'] ?? null) && is_array($latest['detections'])
            ? array_slice($latest['detections'], 0, 8)
            : [];

        return view('livewire.dashboard', [
            'mlOnline' => $online,
            'stats' => [
                'total' => $online ? $total : null,
                'green' => $online ? $green : null,
                'yellow' => $online ? $yellow : null,
                'red' => $online ? $red : null,
                'inferenceFps' => $online ? ($summary['inference_fps'] ?? null) : null,
                'latencyMs' => $online ? ($summary['last_latency_ms'] ?? null) : null,
                'cameraFps' => $online ? ($summary['camera_fps'] ?? null) : null,
                'avgLatencyMs' => $online ? ($summary['average_latency_ms'] ?? null) : null,
                'uptime' => $online ? ($summary['uptime_seconds'] ?? null) : null,
                'cameraMode' => $online ? ($summary['camera_mode'] ?? null) : null,
            ],
            'distribution' => $distribution,
            'detections' => $detections,
            'frameAt' => $latest['timestamp'] ?? null,
            'frameCamera' => $latest['camera'] ?? null,
            'confidenceSamples' => $confidence['samples'] ?? [],
            'timeline' => $timeline,
            'generatedAt' => Carbon::now(),
        ]);
    }
}
