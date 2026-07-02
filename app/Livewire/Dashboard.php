<?php

namespace App\Livewire;

use App\Models\Detection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Dashboard'])]
class Dashboard extends Component
{
    #[Url]
    public string $range = 'today';

    #[Url]
    public string $statusFilter = '';

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

    public function render()
    {
        $base = $this->rangeQuery();

        $total = (clone $base)->count();
        $passed = (clone $base)->where('status', 'passed')->count();
        $unreadable = (clone $base)->where('status', 'unreadable')->count();
        $defective = (clone $base)->whereIn('status', ['damaged', 'scratched'])->count();
        $returned = (clone $base)->whereIn('status', ['returned', 'recheck'])->count();
        $passRate = $total > 0 ? round($passed / $total * 100, 1) : 0;

        // Throughput: items detected in the last 60 minutes.
        $lastHour = Detection::where('detected_at', '>=', now()->subHour())->count();
        $throughput = round($lastHour / 60, 1);

        $activeCameras = Detection::where('detected_at', '>=', now()->subDay())
            ->distinct()
            ->count('camera');

        // Recent detections feed (respects the type filter).
        $recent = $this->rangeQuery()
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with('product')
            ->latest('detected_at')
            ->limit(8)
            ->get();

        // Status distribution for the mini breakdown bar.
        $distribution = collect(Detection::STATUSES)->map(function ($meta, $key) use ($base, $total) {
            $count = (clone $base)->where('status', $key)->count();

            return [
                'label' => $meta['label'],
                'color' => $meta['color'],
                'count' => $count,
                'pct' => $total > 0 ? round($count / $total * 100, 1) : 0,
            ];
        })->values();

        return view('livewire.dashboard', [
            'stats' => [
                'total' => $total,
                'passRate' => $passRate,
                'unreadable' => $unreadable,
                'defective' => $defective,
                'returned' => $returned,
                'throughput' => $throughput,
                'activeCameras' => $activeCameras,
            ],
            'recent' => $recent,
            'distribution' => $distribution,
            'generatedAt' => Carbon::now(),
        ]);
    }
}
