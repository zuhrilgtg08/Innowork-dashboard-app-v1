<div wire:poll.visible.5s class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Vision Sorting Overview</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Real-time AI object detection and color sorting monitoring with the Advantech iCAM-300.</p>
        </div>
        <div class="flex items-center gap-2">
            @php
                $ml = app(\App\Services\MlClient::class);
                $healthy = $ml->healthy();
                $summary = Cache::remember('ml.stats.summary', now()->addSeconds(5), fn () => $ml->statsSummary());
                $serviceReachable = $healthy;
                $modelLoaded = is_array($summary) && ($summary['model_loaded'] ?? false);
                $cameraConnected = is_array($summary) && ($summary['camera_connected'] ?? false);
            @endphp

            @if (!$serviceReachable)
                <span class="inline-flex items-center gap-2 rounded-full bg-red-100 px-3 py-2 text-xs font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-400">
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-red-500"></span>
                    </span>
                    ML Service Offline
                </span>
            @elseif (!$modelLoaded)
                <span class="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-800 dark:bg-amber-500/15 dark:text-amber-400">
                    <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                    Model Error
                </span>
            @else
                <span class="inline-flex items-center gap-2 rounded-full bg-green-100 px-3 py-2 text-xs font-semibold text-green-700 dark:bg-green-500/15 dark:text-green-400">
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-500 opacity-75"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-green-500"></span>
                    </span>
                    ML Service Online
                </span>
            @endif
            <button wire:click="exportReport" wire:loading.attr="disabled" wire:target="exportReport" class="btn-primary">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                <span wire:loading.remove wire:target="exportReport">Export Report</span>
                <span wire:loading wire:target="exportReport">Exporting...</span>
            </button>
        </div>
    </div>

    @unless ($mlOnline)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            Waiting for ML service — live counts, previews, and graphs resume automatically once the Python service is reachable.
        </div>
    @endunless

    <!-- YOLO Live Preview -->
    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 p-5 dark:border-gray-700">
            <div>
                <h3 class="font-bold text-gray-900 dark:text-white">YOLO Live Preview</h3>
                <p class="text-xs text-gray-400">ICAM-300 live annotated stream</p>
            </div>
            @if ($mlOnline && $modelLoaded)
                <x-status-badge color="green" label="Model Loaded" />
            @else
                <x-status-badge color="red" label="YOLO Preview Unavailable" />
            @endif
        </div>
        @if ($mlOnline && $modelLoaded)
            <div class="relative bg-gray-900">
                {{-- wire:ignore keeps the MJPEG connection alive across polls: without it every
                    poll re-morphs the <img> and the browser restarts the stream (flicker + a
                    pinned proxy worker that also slows down menu navigation). --}}
                <div wire:ignore>
                    <img src="{{ route('ml.camera.preview') }}" alt="YOLO annotated preview" data-mjpeg class="aspect-[4/3] w-full object-cover">
                </div>
                <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded bg-black/60 px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-white">
                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-red-500"></span>
                    <span>LIVE</span>
                </span>
            </div>
            <div id="ml-preview-status" class="flex flex-wrap items-center gap-x-3 gap-y-1 p-5 text-sm text-gray-500 dark:text-gray-400">
                <span class="font-medium text-green-600 dark:text-green-400">Model Loaded</span>
                <span class="separator">|</span>
                <span class="font-medium text-blue-600 dark:text-blue-400" id="ml-camera-status">{{ $cameraConnected ? 'Camera Connected' : 'Camera Disconnected' }}</span>
                <span class="separator">|</span>
                <span class="font-medium text-purple-600 dark:text-purple-400" id="ml-fps">Camera FPS: {{ $stats['cameraFps'] !== null ? number_format((float) $stats['cameraFps'], 1) : '—' }} / Inference FPS: {{ $stats['inferenceFps'] !== null ? number_format((float) $stats['inferenceFps'], 1) : '—' }}</span>
                <span class="separator">|</span>
                <span class="font-medium text-orange-600 dark:text-orange-400" id="ml-latency">Latency: {{ $stats['latencyMs'] !== null ? number_format((float) $stats['latencyMs'], 1).' ms' : '—' }}</span>
            </div>
        @else
            <div class="flex aspect-[4/3] w-full flex-col items-center justify-center gap-2 bg-gray-900 p-6 text-center">
                <p class="text-sm font-semibold text-gray-200">YOLO Preview Unavailable</p>
                <p class="max-w-xs text-xs text-gray-500">Waiting for the ML service.</p>
            </div>
        @endif
    </div>

    <!-- Stat cards (live runtime data; honest offline states, never fake) -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-stat-card label="Total Detections" :value="$stats['total'] !== null ? number_format($stats['total']) : '—'" tone="blue" :sub="$mlOnline ? 'Objects detected by the AI model' : 'Waiting for ML service'">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5ZM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5ZM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5ZM16.5 13.5h4.125c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125H16.5A1.125 1.125 0 0 1 15.375 19.5v-4.125c0-.621.504-1.125 1.125-1.125Z" /></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="GREEN" :value="$stats['green'] !== null ? number_format($stats['green']) : '—'" tone="green" :sub="$mlOnline ? 'Green objects detected' : 'Waiting for ML service'">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="YELLOW" :value="$stats['yellow'] !== null ? number_format($stats['yellow']) : '—'" tone="amber" :sub="$mlOnline ? 'Yellow objects detected' : 'Waiting for ML service'">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="RED" :value="$stats['red'] !== null ? number_format($stats['red']) : '—'" tone="red" :sub="$mlOnline ? 'Red objects detected' : 'Waiting for ML service'">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Inference FPS" :value="$stats['inferenceFps'] !== null ? number_format((float) $stats['inferenceFps'], 1) : '—'" tone="purple" :sub="$mlOnline ? 'Current AI processing rate' : 'Waiting for ML service'">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" /></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Inference Latency" :value="$stats['latencyMs'] !== null ? number_format((float) $stats['latencyMs'], 1).' ms' : '—'" tone="orange" :sub="$mlOnline ? 'Latest model inference time' : 'Waiting for ML service'">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></x-slot>
        </x-stat-card>
    </div>

    <!-- Detection distribution (GREEN / YELLOW / RED only) -->
    <div class="card p-5">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 dark:text-white">Detection Distribution</h3>
            <span class="text-xs text-gray-400">Updated {{ $generatedAt->format('H:i:s') }}</span>
        </div>
        <div class="flex h-3 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
            @foreach ($distribution as $d)
                @if ($d['pct'] > 0)
                    <div class="h-full" style="width: {{ $d['pct'] }}%" title="{{ $d['label'] }}: {{ $d['count'] }}">
                        <x-status-badge :color="$d['color']" class="!h-full !w-full !rounded-none !p-0 !block" />
                    </div>
                @endif
            @endforeach
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
            @foreach ($distribution as $d)
                <div class="flex items-center gap-2 text-sm">
                    <x-status-badge :color="$d['color']" class="!px-1.5 !py-1.5" />
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-white">{{ $mlOnline ? number_format($d['count']) : '—' }}</p>
                        <p class="text-xs text-gray-400">{{ $d['label'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Recent detections (live YOLO results) -->
    <div class="card overflow-hidden">
        <div class="flex flex-col gap-3 border-b border-gray-100 p-5 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="font-bold text-gray-900 dark:text-white">Recent Detections</h3>
                <p class="text-xs text-gray-400">
                    @if ($frameCamera){{ $frameCamera }} · @endif
                    @if ($frameAt){{ \Carbon\Carbon::parse($frameAt)->diffForHumans() }}@else Live YOLO results @endif
                </p>
            </div>
            <span wire:loading class="text-xs text-brand-500">Refreshing…</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                        <th class="px-5 py-3 font-semibold">Time</th>
                        <th class="px-5 py-3 font-semibold">Class</th>
                        <th class="px-5 py-3 font-semibold">Confidence</th>
                        <th class="px-5 py-3 font-semibold">Center X</th>
                        <th class="px-5 py-3 font-semibold">Center Y</th>
                        <th class="px-5 py-3 font-semibold">Norm X</th>
                        <th class="px-5 py-3 font-semibold">Norm Y</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($detections as $d)
                        @php $tone = ['GREEN' => 'green', 'YELLOW' => 'amber', 'RED' => 'red'][$d['class_name'] ?? ''] ?? 'gray'; @endphp
                        <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $frameAt ? \Carbon\Carbon::parse($frameAt)->format('H:i:s') : '—' }}</td>
                            <td class="px-5 py-3"><x-status-badge :color="$tone" :label="$d['class_name'] ?? '—'" /></td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ isset($d['confidence']) ? number_format((float) $d['confidence'], 1).'%' : '—' }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-gray-600 dark:text-gray-300">{{ $d['center']['x'] ?? '—' }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-gray-600 dark:text-gray-300">{{ $d['center']['y'] ?? '—' }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-gray-600 dark:text-gray-300">{{ isset($d['normalized']['center_x']) ? number_format((float) $d['normalized']['center_x'], 4) : '—' }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-gray-600 dark:text-gray-300">{{ isset($d['normalized']['center_y']) ? number_format((float) $d['normalized']['center_y'], 4) : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                @if ($mlOnline)
                                    <x-empty-state title="No detections yet" message="Objects will appear here when the AI model detects GREEN, YELLOW, or RED objects." />
                                @else
                                    <x-empty-state title="ML Service Offline" message="Waiting for the ML service. Live detections resume automatically once it is reachable." />
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Detection analytics (lightweight SVG, real runtime data only) -->
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <!-- Confidence trend -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Confidence Trend</h3>
            <p class="text-xs text-gray-400">Recent per-detection confidence samples</p>
            @php $trend = array_slice($confidenceSamples, -60); @endphp
            @if (count($trend) >= 2)
                @php
                    $w = 560; $h = 140; $pad = 10; $n = count($trend);
                    $pts = [];
                    foreach ($trend as $i => $s) {
                        $x = $pad + ($n > 1 ? $i / ($n - 1) : 0.5) * ($w - 2 * $pad);
                        $y = $h - $pad - (max(0, min(100, (float) ($s['confidence'] ?? 0))) / 100) * ($h - 2 * $pad);
                        $pts[] = ['x' => round($x, 1), 'y' => round($y, 1), 'c' => $s['class'] ?? ''];
                    }
                    $dotColor = fn ($c) => ['GREEN' => '#22c55e', 'YELLOW' => '#eab308', 'RED' => '#ef4444'][$c] ?? '#94a3b8';
                @endphp
                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="mt-4 h-36 w-full" role="img" aria-label="Confidence trend">
                    <polyline points="{{ implode(' ', array_map(fn ($p) => $p['x'].','.$p['y'], $pts)) }}" fill="none" stroke="#6366f1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    @foreach ($pts as $p)
                        <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="3" fill="{{ $dotColor($p['c']) }}" />
                    @endforeach
                </svg>
                <p class="mt-2 text-xs text-gray-400">{{ count($trend) }} samples · latest {{ number_format((float) ($trend[count($trend) - 1]['confidence'] ?? 0), 1) }}%</p>
            @else
                <div class="mt-4"><x-empty-state title="No samples yet" message="Confidence samples appear here once the AI model detects objects." /></div>
            @endif
        </div>

        <!-- Detection timeline -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Detection Timeline</h3>
            <p class="text-xs text-gray-400">Detections per {{ $timeline['bucket_seconds'] ?? 60 }}s interval</p>
            @php
                $labels = $timeline['labels'] ?? [];
                $series = $timeline['series'] ?? [];
                $bucketMax = 1;
                $bucketedTotal = 0;
                foreach ($labels as $i => $lab) {
                    $bucketTotal = ($series['GREEN'][$i] ?? 0) + ($series['YELLOW'][$i] ?? 0) + ($series['RED'][$i] ?? 0);
                    $bucketMax = max($bucketMax, $bucketTotal);
                    $bucketedTotal += $bucketTotal;
                }
            @endphp
            @if (count($labels) > 0 && $bucketedTotal > 0)
                <div class="mt-4 flex h-36 items-end gap-[3px]">
                    @foreach ($labels as $i => $lab)
                        @php
                            $g = $series['GREEN'][$i] ?? 0; $y = $series['YELLOW'][$i] ?? 0; $r = $series['RED'][$i] ?? 0;
                            $tot = $g + $y + $r;
                        @endphp
                        <div class="flex flex-1 flex-col justify-end rounded-t" style="height: {{ max(4, $tot / $bucketMax * 100) }}%" title="{{ $lab }} — GREEN {{ $g }}, YELLOW {{ $y }}, RED {{ $r }}">
                            @if ($r > 0)<div class="w-full bg-red-500" style="height: {{ $r / $tot * 100 }}%"></div>@endif
                            @if ($y > 0)<div class="w-full bg-amber-400" style="height: {{ $y / $tot * 100 }}%"></div>@endif
                            @if ($g > 0)<div class="w-full bg-green-500" style="height: {{ $g / $tot * 100 }}%"></div>@endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-[11px] text-gray-400">
                    <span>{{ $labels[0] ?? '' }}</span><span>{{ $labels[count($labels) - 1] ?? '' }}</span>
                </div>
            @else
                <div class="mt-4"><x-empty-state title="No timeline data yet" message="Bucketed detection counts appear here once the AI model detects objects." /></div>
            @endif
        </div>
    </div>

    <!-- ML performance -->
    <div class="card p-5">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 dark:text-white">ML Performance</h3>
            <span class="text-xs text-gray-400">Live runtime telemetry</span>
        </div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Camera FPS</p>
                <p class="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">{{ $stats['cameraFps'] !== null ? number_format((float) $stats['cameraFps'], 1) : '—' }}</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Inference FPS</p>
                <p class="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">{{ $stats['inferenceFps'] !== null ? number_format((float) $stats['inferenceFps'], 1) : '—' }}</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Latest Latency</p>
                <p class="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">{{ $stats['latencyMs'] !== null ? number_format((float) $stats['latencyMs'], 1).' ms' : '—' }}</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Avg Latency</p>
                <p class="mt-1 text-xl font-extrabold text-gray-900 dark:text-white">{{ $stats['avgLatencyMs'] !== null ? number_format((float) $stats['avgLatencyMs'], 1).' ms' : '—' }}</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Camera Mode</p>
                <p class="mt-1 text-xl font-extrabold uppercase text-gray-900 dark:text-white">{{ $stats['cameraMode'] ?? '—' }}</p>
            </div>
        </div>
    </div>
</div>

@script
<script>
    // Abort the open MJPEG preview the moment SPA navigation starts, so the
    // pinned Laravel proxy worker is released and the next menu paints fast.
    document.addEventListener('livewire:navigating', () => {
        document.querySelectorAll('img[data-mjpeg]').forEach((img) => {
            img.removeAttribute('src');
        });
    }, { once: true });
</script>
@endscript
