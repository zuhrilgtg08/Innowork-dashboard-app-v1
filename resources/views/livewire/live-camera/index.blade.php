<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Live Camera</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Real-time visual monitoring for the Vision Sorting system.</p>
        </div>
        @if ($mlOnline)
            <span class="inline-flex items-center gap-2 rounded-full bg-green-100 px-3 py-1.5 text-xs font-semibold text-green-700 dark:bg-green-500/15 dark:text-green-400">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-500 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-green-500"></span>
                </span>
                AI Service Online
            </span>
        @else
            <span class="inline-flex items-center gap-2 rounded-full bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-400">
                <span class="h-2 w-2 rounded-full bg-red-500"></span>
                AI Service Offline
            </span>
        @endif
    </div>

    <!-- Camera fleet overview (multi-camera) -->
    @if ($fleet->isNotEmpty())
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($fleet as $cam)
                <div class="card p-4">
                    <div class="flex items-center justify-between">
                        <p class="font-bold text-gray-900 dark:text-white">{{ $cam['name'] }}</p>
                        @if ($cam['live'])
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-green-700 dark:bg-green-500/15 dark:text-green-400"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-green-500"></span> RTSP</span>
                        @else
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-gray-500 dark:bg-gray-700 dark:text-gray-400">SIM</span>
                        @endif
                    </div>
                    <p class="mt-0.5 text-xs text-gray-400">{{ $cam['conveyor'] ?? '—' }}</p>
                    <div class="mt-3 flex items-end justify-between">
                        <div>
                            <p class="text-2xl font-extrabold text-gray-900 dark:text-white">{{ number_format($cam['detections']) }}</p>
                            <p class="text-[11px] uppercase tracking-wider text-gray-400">Today's Detections</p>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-red-600 dark:text-red-400">{{ number_format($cam['failed']) }}</p>
                            <p class="text-[11px] uppercase tracking-wider text-gray-400">Errors</p>
                        </div>
                    </div>
                    <p class="mt-2 text-[11px] text-gray-400">
                        {{ $cam['last_seen'] ? 'Last seen '.\Illuminate\Support\Carbon::parse($cam['last_seen'])->diffForHumans() : 'No activity yet' }}
                    </p>
                </div>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Single live camera card -->
        <div class="lg:col-span-2">
            @if ($cameraSource === 'icam')
            <div wire:poll.5s="refreshRuntime" class="space-y-4">
                @php
                    $modelLoaded = (bool) ($runtime['model_loaded'] ?? false);
                    $camConnected = (bool) ($runtime['camera_connected'] ?? $runtimeCamera['connected'] ?? false);
                    $camMode = strtoupper($runtime['camera_mode'] ?? $runtimeCamera['mode'] ?? 'OFFLINE');
                    $camFps = $runtime['camera_fps'] ?? $runtimeCamera['fps'] ?? null;
                    $infFps = $runtime['inference_fps'] ?? null;
                    $latMs = $runtime['last_inference_ms'] ?? null;
                    $primary = $runtimeDetections[0] ?? null;
                    $primaryTone = ['GREEN' => 'green', 'YELLOW' => 'amber', 'RED' => 'red'][$primary['class_name'] ?? ''] ?? 'gray';
                @endphp
                <!-- YOLO Preview (primary): annotated stream from the shared runtime -->
                <div class="card overflow-hidden" x-data="{ streamOk: {{ $mlOnline ? 'true' : 'false' }} }">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 p-4 dark:border-gray-700">
                        <div>
                            <h3 class="font-bold text-gray-900 dark:text-white">YOLO Preview</h3>
                            <p class="text-xs text-gray-400">Advantech iCAM-300 → best.pt → GREEN / YELLOW / RED</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-status-badge :color="$modelLoaded ? 'green' : 'red'" :label="$modelLoaded ? 'Model Loaded' : 'Model Error'" />
                            <x-status-badge :color="$camConnected ? 'green' : 'gray'" :label="$camConnected ? 'Camera Connected' : 'Camera Offline'" />
                        </div>
                    </div>
                    <div class="relative aspect-video bg-gray-900">
                        <img src="{{ $previewUrl }}" alt="YOLO annotated preview"
                             class="h-full w-full object-cover"
                             x-show="streamOk"
                             x-on:error="streamOk = false" x-on:load="streamOk = true" />
                        <div x-show="!streamOk" class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-6 text-center text-gray-400">
                            <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke-width="1.4" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                            <p class="text-sm font-semibold text-gray-200">Camera Stream Unavailable</p>
                            <p class="text-xs text-gray-500">Waiting for the Advantech iCAM-300 stream.</p>
                        </div>
                        <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded bg-black/60 px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-white">
                            <span class="h-1.5 w-1.5 rounded-full bg-red-500" :class="streamOk && 'animate-pulse'"></span>
                            <span x-text="streamOk ? 'LIVE' : 'OFF'"></span>
                        </span>
<span class="absolute right-3 top-3 rounded bg-black/60 px-2 py-1 font-mono text-[11px] text-white">{{ $camera }} · Vision Stream</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4">
                        <div>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400">Camera FPS</p>
                            <p class="text-lg font-extrabold text-gray-900 dark:text-white">{{ $camFps !== null ? number_format((float) $camFps, 1) : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400">Inference FPS</p>
                            <p class="text-lg font-extrabold text-gray-900 dark:text-white">{{ $infFps !== null ? number_format((float) $infFps, 1) : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400">Latency</p>
                            <p class="text-lg font-extrabold text-gray-900 dark:text-white">{{ $latMs !== null ? number_format((float) $latMs, 1).' ms' : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400">Camera Mode</p>
                            <p class="text-lg font-extrabold uppercase text-gray-900 dark:text-white">{{ $camMode }}</p>
                        </div>
                    </div>
                </div>

                <!-- Current Detection + remaining detections -->
                <div class="card p-5">
                    <h3 class="font-bold text-gray-900 dark:text-white">Current Detection</h3>
                    <p class="text-xs text-gray-400">
                        @if ($runtimeFrameAt){{ \Carbon\Carbon::parse($runtimeFrameAt)->diffForHumans() }}@else Primary detection (highest confidence) @endif
                        @if ($runtimeFrameCamera)· {{ $runtimeFrameCamera }}@endif
                    </p>
                    @if ($primary)
                        @php
                            $box = $primary['bbox'] ?? []; $ctr = $primary['center'] ?? []; $nrm = $primary['normalized'] ?? [];
                        @endphp
                        <div class="mt-4 flex items-center gap-3">
                            <x-status-badge :color="$primaryTone" :label="$primary['class_name'] ?? '—'" />
                            <p class="text-3xl font-extrabold tracking-tight text-gray-900 dark:text-white">{{ isset($primary['confidence']) ? number_format((float) $primary['confidence'], 1).'%' : '—' }}</p>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Class</dt>
                                <dd class="mt-1 font-bold text-gray-900 dark:text-white">{{ $primary['class_name'] ?? '—' }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Confidence</dt>
                                <dd class="mt-1 font-bold text-gray-900 dark:text-white">{{ isset($primary['confidence']) ? number_format((float) $primary['confidence'], 1).'%' : '—' }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Center X / Y</dt>
                                <dd class="mt-1 font-mono text-xs font-bold text-gray-900 dark:text-white">{{ $ctr['x'] ?? '—' }} / {{ $ctr['y'] ?? '—' }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Normalized X / Y</dt>
                                <dd class="mt-1 font-mono text-xs font-bold text-gray-900 dark:text-white">{{ isset($nrm['center_x']) ? number_format((float) $nrm['center_x'], 4) : '—' }} / {{ isset($nrm['center_y']) ? number_format((float) $nrm['center_y'], 4) : '—' }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">BBox</dt>
                                <dd class="mt-1 font-mono text-xs font-bold text-gray-900 dark:text-white">{{ isset($box['x1']) ? "{$box['x1']},{$box['y1']} → {$box['x2']},{$box['y2']}" : '—' }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Frame Resolution</dt>
                                <dd class="mt-1 font-mono text-xs font-bold text-gray-900 dark:text-white">{{ isset($runtimeFrame['width']) ? $runtimeFrame['width'].' × '.$runtimeFrame['height'] : '—' }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Timestamp</dt>
                                <dd class="mt-1 font-mono text-xs font-bold text-gray-900 dark:text-white">{{ $runtimeFrameAt ? \Carbon\Carbon::parse($runtimeFrameAt)->format('H:i:s') : '—' }}</dd>
                            </div>
                        </dl>
                        @if (count($runtimeDetections) > 1)
                            <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-gray-400">Also in frame</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach (array_slice($runtimeDetections, 1) as $other)
                                    @php $tone = ['GREEN' => 'green', 'YELLOW' => 'amber', 'RED' => 'red'][$other['class_name'] ?? ''] ?? 'gray'; @endphp
                                    <x-status-badge :color="$tone" :label="($other['class_name'] ?? '?').' '.(isset($other['confidence']) ? number_format((float) $other['confidence'], 1).'%' : '')" />
                                @endforeach
                            </div>
                        @endif
                    @else
                        <div class="mt-4">
                            @if ($mlOnline)
                                <x-empty-state title="No detections yet" message="Objects will appear here when the AI model detects GREEN, YELLOW, or RED objects." />
                            @else
                                <x-empty-state title="ML Service Offline" message="Waiting for the ML service. Live detections resume automatically once it is reachable." />
                            @endif
                        </div>
                    @endif
                </div>

                <!-- Runtime graphs -->
                <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <div class="card p-5">
                        <h3 class="font-bold text-gray-900 dark:text-white">Confidence Trend</h3>
                        <p class="text-xs text-gray-400">Recent per-detection confidence samples</p>
                        @php $trend = array_slice($runtimeConfidence, -60); @endphp
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
                        @else
                            <div class="mt-4"><x-empty-state title="No samples yet" message="Confidence samples appear here once the AI model detects objects." /></div>
                        @endif
                    </div>
                    <div class="card p-5">
                        <h3 class="font-bold text-gray-900 dark:text-white">Detection Timeline</h3>
                        <p class="text-xs text-gray-400">Detections per {{ $runtimeTimeline['bucket_seconds'] ?? 60 }}s interval</p>
                        @php
                            $labels = $runtimeTimeline['labels'] ?? [];
                            $series = $runtimeTimeline['series'] ?? [];
                            $bucketMax = 1; $bucketedTotal = 0;
                            foreach ($labels as $i => $lab) {
                                $bt = ($series['GREEN'][$i] ?? 0) + ($series['YELLOW'][$i] ?? 0) + ($series['RED'][$i] ?? 0);
                                $bucketMax = max($bucketMax, $bt);
                                $bucketedTotal += $bt;
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

                <!-- Raw Camera (secondary, no overlay) -->
                <details class="card overflow-hidden">
                    <summary class="cursor-pointer p-4 font-bold text-gray-900 dark:text-white">Raw Camera <span class="ml-1 text-xs font-normal text-gray-400">— camera feed without YOLO overlay</span></summary>
                    <div class="relative aspect-video bg-gray-900">
                        <img src="{{ route('ml.camera.raw') }}" alt="Raw camera feed" class="h-full w-full object-cover" loading="lazy" />
                        <span class="absolute left-3 top-3 rounded bg-black/60 px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-white">RAW</span>
                    </div>
                    <p class="p-4 text-xs text-gray-400">Advantech iCAM-300 — Industrial Camera · Automatic inference through the AI service.</p>
                </details>
            </div>
            @else
            <div class="card overflow-hidden"
                 x-data="webcam()"
                 x-init="init()">
                @if ($visionMode)
                    <div class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        Vision Sorting mode uses the Advantech iCAM-300 server stream for production.
                        The browser webcam below is for local development only and never starts automatically.
                    </div>
                @endif
                <div x-show="embedded" style="display: none;" class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    Browser webcam access may be restricted when SortVision is embedded in IoT Suite.
                    Use the Advantech iCAM-300 server stream for production operation.
                </div>
                <div class="relative aspect-video bg-gray-900">
                    <!-- Live webcam feed -->
                    <video x-ref="video" autoplay playsinline muted
                           class="h-full w-full object-cover" x-show="active" style="display:none;"></video>
                    <canvas x-ref="canvas" class="hidden"></canvas>

                    <!-- Idle / error placeholder -->
                    <div x-show="!active" class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center text-gray-500">
                        <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke-width="1.4" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                        <p class="text-sm font-medium" x-text="error || 'Camera inactive'"></p>
                        <button @click="start()" x-show="!error" class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-400 focus:ring-offset-2 focus:ring-offset-gray-900">Start Camera</button>
                    </div>

                    <!-- Inspecting indicator (subtle corner badge for the live loop) -->
                    <div x-show="uploading" x-transition.opacity class="absolute bottom-3 left-3 inline-flex items-center gap-2 rounded-lg bg-black/60 px-3 py-1.5 text-xs font-semibold text-white">
                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        Analyzing frame...
                    </div>

                    <!-- Overlay badges -->
                    <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded bg-black/60 px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-white">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500" :class="active && 'animate-pulse'"></span>
                        <span x-text="active ? 'LIVE' : 'OFF'"></span>
                    </span>
                    <span x-show="active && auto" class="absolute left-20 top-3 inline-flex items-center gap-1.5 rounded bg-brand-600/80 px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-white">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-white"></span> Auto-inspect
                    </span>
                    <span class="absolute right-3 top-3 rounded bg-black/60 px-2 py-1 font-mono text-[11px] text-white">{{ $camera }} · {{ $conveyor }}</span>
                </div>

                <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="font-bold text-gray-900 dark:text-white">{{ $camera }} — Development Webcam</p>
                        <p class="text-xs text-gray-400">
                            Device: <span x-text="deviceLabel || '—'"></span>
                            <span x-show="active" class="ml-1" x-text="auto ? '· Automatic inspection every ' + (intervalMs/1000) + ' sec' : '· Inspection paused'"></span>
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button @click="toggleAuto()" x-show="active" aria-label="Pause or resume automatic inspection"
                                class="rounded-lg px-4 py-2 text-xs font-semibold text-white transition focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
                                :class="auto ? 'bg-amber-500 hover:bg-amber-600 focus:ring-amber-400' : 'bg-brand-600 hover:bg-brand-700 focus:ring-brand-500'">
                            <span x-show="auto">Pause Inspection</span>
                            <span x-show="!auto">Resume Inspection</span>
                        </button>
                        <button @click="start()" x-show="!active" class="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800">Start Camera</button>
                        <button @click="stop()" x-show="active" class="rounded-lg bg-gray-100 px-4 py-2 text-xs font-semibold text-gray-600 transition hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-400 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Stop</button>
                    </div>
                </div>
            </div>
            @endif

            @error('frame')
                <p class="mt-3 rounded-lg bg-red-50 px-4 py-2 text-sm font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">{{ $message }}</p>
            @enderror

            <!-- Last inference verdict -->
            @if ($lastResult && ($lastResult['status'] ?? '') !== 'error')
                <div class="card mt-4 flex items-center justify-between p-4">
                    <div class="flex items-center gap-3">
                        <x-status-badge :color="$lastResult['color'] ?? 'gray'" :label="$lastResult['label'] ?? ucfirst($lastResult['status'])" />
                        <div>
                            <p class="text-sm font-bold text-gray-900 dark:text-white">Latest Inspection Result</p>
                            <p class="text-xs text-gray-400">Confidence {{ number_format((float) ($lastResult['confidence'] ?? 0), 1) }}%</p>
                        </div>
                    </div>
                    @if ($lastResult['rejected'] ?? false)
                        <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-bold text-red-700 dark:bg-red-500/15 dark:text-red-400">AUTO-REJECTED</span>
                    @endif
                </div>
            @endif

            <!-- Today's aggregate for this single camera -->
            <div class="mt-4 grid grid-cols-3 gap-4" wire:poll.5s>
                <div class="card p-4 text-center">
                    <p class="text-2xl font-extrabold text-gray-900 dark:text-white">{{ number_format($stats['total']) }}</p>
                    <p class="text-xs text-gray-400">Today's Detections</p>
                </div>
                <div class="card p-4 text-center">
                    <p class="text-2xl font-extrabold text-green-600 dark:text-green-400">{{ number_format($stats['passed']) }}</p>
                    <p class="text-xs text-gray-400">Passed</p>
                </div>
                <div class="card p-4 text-center">
                    <p class="text-2xl font-extrabold text-red-600 dark:text-red-400">{{ number_format($stats['failed']) }}</p>
                    <p class="text-xs text-gray-400">Errors</p>
                </div>
            </div>
        </div>

        <!-- Detection feed -->
        <div class="card flex flex-col">
            <div class="border-b border-gray-100 p-4 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white">Detection Feed</h3>
                <p class="text-xs text-gray-400">
                    @if ($stats['last_seen'])
                        Last seen {{ \Illuminate\Support\Carbon::parse($stats['last_seen'])->diffForHumans() }}
                    @else
                        No detections yet
                    @endif
                </p>
            </div>
            <div class="scrollbar-thin flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
                @forelse ($feed as $item)
                    <div class="flex items-center gap-3 px-4 py-3">
                        <x-status-badge :color="$item->statusColor()" :label="$item->statusLabel()" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">{{ $item->product?->name ?? $item->code }}</p>
                            <p class="text-xs text-gray-400">{{ $item->camera }} &middot; {{ $item->detected_at?->diffForHumans() }}</p>
                        </div>
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ number_format((float) $item->confidence, 1) }}%</span>
                    </div>
                @empty
                    <x-empty-state title="No detections yet" message="Detections will appear here when the camera processes an object." />
                @endforelse
            </div>
        </div>
    </div>
</div>

@script
<script>
    Alpine.data('webcam', () => ({
        active: false,
        error: '',
        deviceLabel: '',
        uploading: false,
        auto: true,          // run inference continuously while the camera is live
        intervalMs: 3000,    // cadence of the auto-inspect loop
        timer: null,
        stream: null,
        embedded: false,     // true when running inside an iframe (e.g. IoT Suite)
        init() {
            // Never request camera permission automatically: it may only be
            // requested after the user explicitly clicks "Start Camera". This
            // keeps the page usable inside the Advantech IoT Suite iframe,
            // where browser webcam access is typically restricted.
            this.embedded = window.self !== window.top;
            // Release the camera when navigating away (Livewire SPA nav).
            document.addEventListener('livewire:navigating', () => this.stop(), { once: true });
        },
        async start() {
            this.error = '';
            if (!navigator.mediaDevices?.getUserMedia) {
                this.error = 'This browser does not support camera access.';
                return;
            }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                this.$refs.video.srcObject = this.stream;
                this.deviceLabel = this.stream.getVideoTracks()[0]?.label || 'Webcam';
                this.active = true;
                // Begin live inference after an explicit start, no auto-start on mount.
                this.startAuto();
            } catch (e) {
                if (e.name === 'NotAllowedError') {
                    this.error = 'Camera access was denied. Allow camera access in your browser settings and try again.';
                } else if (e.name === 'NotFoundError' || e.name === 'OverconstrainedError') {
                    this.error = 'No camera was found on this device.';
                } else {
                    this.error = 'Camera is unavailable.';
                }
                this.active = false;
            }
        },
        startAuto() {
            this.stopAuto();
            if (!this.auto || !this.active) return;
            // Kick off the first inspection right away, then keep going on interval.
            this.capture();
            this.timer = setInterval(() => this.capture(), this.intervalMs);
        },
        stopAuto() {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }
        },
        toggleAuto() {
            this.auto = !this.auto;
            this.auto ? this.startAuto() : this.stopAuto();
        },
        capture() {
            // The uploading guard makes overlapping ticks a no-op: if inference
            // is still running when the interval fires, that frame is skipped.
            if (!this.active || this.uploading) return;
            const video = this.$refs.video;
            // Skip while the stream is not ready (readyState < HAVE_CURRENT_DATA)
            // so blank frames are not captured before the camera warms up.
            if (video.readyState < 2 || !video.videoWidth) return;
            const canvas = this.$refs.canvas;
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            this.uploading = true;
            canvas.toBlob((blob) => {
                const file = new File([blob], 'frame.jpg', { type: 'image/jpeg' });
                // Livewire temporary upload, then run inference on the stored frame.
                $wire.upload('frame', file, () => {
                    $wire.inferFrame().then(() => { this.uploading = false; });
                }, () => { this.uploading = false; });
            }, 'image/jpeg', 0.9);
        },
        stop() {
            this.stopAuto();
            this.stream?.getTracks().forEach(t => t.stop());
            this.stream = null;
            this.active = false;
        },
    }));
</script>
@endscript
