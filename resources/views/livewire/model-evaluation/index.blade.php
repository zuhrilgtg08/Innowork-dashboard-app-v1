<div wire:poll.5s="refreshPreview" class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Model Evaluation</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Vision Sorting · live YOLO model inspection (visualization only)</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($previewing)
                <x-status-badge color="amber" label="Preview — visualization only" />
                <button wire:click="stopPreview" class="btn-secondary !py-2 text-sm">Stop Model Preview</button>
            @else
                <x-status-badge color="blue" label="Vision Sorting" />
                <button wire:click="startPreview" class="btn-primary !py-2 text-sm" @if (! $mlOnline) disabled @endif>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" /></svg>
                    Start Model Preview
                </button>
            @endif
        </div>
    </div>

    <!-- Active model + preview + info panel -->
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <!-- Active model information (real values from ML service) -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Active Model</h3>
            <p class="text-xs text-gray-400">Live values from the ML service</p>
            @if ($modelInfo)
                @php
                    $size = $modelInfo['file_size_bytes'] ?? null;
                    $sizeLabel = $size ? number_format($size / 1048576, 1).' MB' : '—';
                    $classes = $modelInfo['classes'] ?? [];
                    uksort($classes, fn ($a, $b) => (int) $a <=> (int) $b);
                @endphp
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Active Model</dt>
                        <dd class="font-mono text-xs font-bold text-gray-900 dark:text-white">{{ basename($activeModelPath ?: ($modelInfo['path'] ?? '')) ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="shrink-0 text-gray-500 dark:text-gray-400">Model Path</dt>
                        <dd class="truncate font-mono text-xs text-gray-900 dark:text-white" title="{{ $activeModelPath }}">{{ $activeModelPath ?: '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Model Status</dt>
                        <dd>
                            @if ($activeModelExists)
                                <x-status-badge color="green" label="Loaded" />
                            @else
                                <x-status-badge color="red" label="Error" />
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Classes</dt>
                        <dd class="font-bold text-gray-900 dark:text-white">{{ $modelInfo['class_count'] ?? count($classes) }}</dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Confidence Threshold</dt>
                        <dd class="font-bold text-gray-900 dark:text-white">{{ number_format($confThreshold * 100, 1) }}%</dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Model File Size</dt>
                        <dd class="font-bold text-gray-900 dark:text-white">{{ $sizeLabel }}</dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Inference Device</dt>
                        <dd class="font-mono text-xs font-bold uppercase text-gray-900 dark:text-white">{{ $modelInfo['inference_device'] ?? '—' }}{{ ! empty($modelInfo['cuda_available']) ? ' · CUDA available' : '' }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Last Inference</dt>
                        <dd class="text-xs text-gray-600 dark:text-gray-300">{{ $lastInferenceAt ? \Carbon\Carbon::parse($lastInferenceAt)->diffForHumans() : '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-gray-400">Class Mapping</p>
                <div class="mt-2 overflow-hidden rounded-xl border border-gray-100 dark:border-gray-700">
                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($classes as $idx => $name)
                                <tr>
                                    <td class="px-4 py-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $idx }}</td>
                                    <td class="px-4 py-2 font-mono text-xs font-bold text-gray-900 dark:text-white">{{ $name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="mt-4">
                    <x-empty-state title="ML service offline" message="Model metadata is unavailable. Start the Python service." />
                </div>
            @endif
        </div>

        <!-- Annotated preview stream -->
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-100 p-5 dark:border-gray-700">
                <div>
                    <h3 class="font-bold text-gray-900 dark:text-white">Model Preview</h3>
                    <p class="text-xs text-gray-400">ICAM-300 → YOLO → bounding boxes</p>
                </div>
                @if ($previewing)
                    <x-status-badge color="green" label="Previewing" />
                @else
                    <x-status-badge color="gray" label="Idle" />
                @endif
            </div>
            <div class="relative bg-gray-900">
                @if ($previewing && $previewUrl)
                    <img src="{{ $previewUrl }}" alt="Annotated YOLO preview" class="aspect-video w-full object-cover">
                @else
                    <div class="flex aspect-video w-full flex-col items-center justify-center gap-2 p-6 text-center">
                        <svg class="h-10 w-10 text-gray-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                        <p class="text-sm font-semibold text-gray-300">Preview stopped</p>
                        <p class="max-w-xs text-xs text-gray-500">Start Model Preview to watch live bounding boxes, class, and confidence overlays.</p>
                    </div>
                @endif
            </div>
            <div class="border-t border-gray-100 bg-amber-50 px-5 py-3 dark:border-gray-700 dark:bg-amber-500/10">
                <p class="text-xs font-medium text-amber-700 dark:text-amber-300">Model Preview never controls the robot: no arm commands, no sorting counter changes.</p>
            </div>
        </div>

        <!-- Preview info panel -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Preview Snapshot</h3>
            <p class="text-xs text-gray-400">{{ $preview['at'] ?? 'No frame analysed yet' }}</p>
            @if ($preview && $preview['detected_class'])
                @php $normTone = ['green' => 'green', 'yellow' => 'amber', 'red' => 'red'][$preview['normalized_color'] ?? ''] ?? 'blue'; @endphp
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gray-400">Detected Class</dt>
                        <dd class="font-mono text-xl font-extrabold text-gray-900 dark:text-white">{{ $preview['detected_class'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Normalized</dt>
                        <dd><x-status-badge :color="$normTone" :label="strtolower($preview['normalized_color'] ?? '—')" /></dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Confidence</dt>
                        <dd class="font-bold text-gray-900 dark:text-white">{{ number_format((float) $preview['confidence'], 1) }}%</dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">BBox</dt>
                        <dd class="font-mono text-xs text-gray-900 dark:text-white">{{ $preview['bbox'] ? implode(', ', $preview['bbox']) : '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Center X / Y</dt>
                        <dd class="font-mono text-xs text-gray-900 dark:text-white">{{ $preview['center_x'] ?? '—' }} / {{ $preview['center_y'] ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Pick Zone</dt>
                        <dd>
                            @if ($preview['in_pick_zone'])
                                <x-status-badge color="green" label="Valid" />
                            @else
                                <x-status-badge color="gray" label="Outside" />
                            @endif
                        </dd>
                    </div>
                </dl>
            @else
                <div class="mt-4">
                    <x-empty-state title="No detection" message="Snapshot appears here once the preview analyses a frame." />
                </div>
            @endif
        </div>
    </div>

    <!-- Runtime graphs (real inference data) -->
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <!-- Detection distribution -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Detection Distribution</h3>
            <p class="text-xs text-gray-400">Detections with color context (all time)</p>
            @php $total = array_sum($distribution); @endphp
            @if ($total > 0)
                <div class="mt-4 flex h-3 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                    @foreach (['green' => 'bg-green-500', 'yellow' => 'bg-amber-400', 'red' => 'bg-red-500'] as $c => $bg)
                        @php $pct = ($distribution[$c] ?? 0) / $total * 100; @endphp
                        @if ($pct > 0)
                            <div class="h-full {{ $bg }}" style="width: {{ $pct }}%" title="{{ strtoupper($c) }}: {{ $distribution[$c] }}"></div>
                        @endif
                    @endforeach
                </div>
                <div class="mt-4 grid grid-cols-3 gap-3">
                    @foreach (['green' => 'green', 'yellow' => 'amber', 'red' => 'red'] as $c => $tone)
                        <div class="flex items-center gap-2 text-sm">
                            <x-status-badge :color="$tone" class="!px-1.5 !py-1.5" />
                            <div>
                                <p class="font-semibold text-gray-900 dark:text-white">{{ number_format($distribution[$c] ?? 0) }}</p>
                                <p class="text-xs text-gray-400">{{ strtoupper($c) }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-4"><x-empty-state title="No data" message="No detections with color context yet." /></div>
            @endif
        </div>

        <!-- Average confidence by class -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Average Confidence by Class</h3>
            <p class="text-xs text-gray-400">Mean model confidence per color</p>
            @php
                $avgMax = ! empty($avgConfidence) ? max(1, max($avgConfidence)) : 1;
                $avgBars = ['green' => ['v' => $avgConfidence['green'] ?? 0, 'bg' => 'bg-green-500'], 'yellow' => ['v' => $avgConfidence['yellow'] ?? 0, 'bg' => 'bg-amber-400'], 'red' => ['v' => $avgConfidence['red'] ?? 0, 'bg' => 'bg-red-500']];
            @endphp
            @if (array_sum(array_column($avgBars, 'v')) > 0)
                <div class="mt-4 space-y-3">
                    @foreach ($avgBars as $c => $bar)
                        <div>
                            <div class="mb-1 flex items-center justify-between text-sm">
                                <span class="font-semibold text-gray-700 dark:text-gray-200">{{ strtoupper($c) }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ number_format((float) $bar['v'], 1) }}%</span>
                            </div>
                            <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-full rounded-full {{ $bar['bg'] }}" style="width: {{ min(100, $bar['v'] / $avgMax * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-4"><x-empty-state title="No data" message="No per-class confidence data yet." /></div>
            @endif
        </div>

        <!-- Inference latency -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Inference Latency Over Time</h3>
            <p class="text-xs text-gray-400">Current preview session (ms per frame)</p>
            @php
                $lat = array_values(array_filter(array_column($previewLog, 'latency_ms'), fn ($v) => $v !== null));
                $lat = array_slice($lat, -30);
            @endphp
            @if (count($lat) >= 2)
                @php
                    $w = 300; $h = 72; $pad = 6;
                    $max = max(1, max($lat)); $min = min($lat);
                    $span = max(1, $max - $min);
                    $pts = [];
                    foreach ($lat as $i => $v) {
                        $x = $pad + (count($lat) > 1 ? $i / (count($lat) - 1) : 0.5) * ($w - 2 * $pad);
                        $y = $h - $pad - (($v - $min) / $span) * ($h - 2 * $pad);
                        $pts[] = round($x, 1).','.round($y, 1);
                    }
                @endphp
                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="mt-4 h-24 w-full" preserveAspectRatio="none" role="img" aria-label="Inference latency">
                    <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="#2563eb" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                </svg>
                <p class="mt-2 text-xs text-gray-400">Last {{ number_format(end($lat), 1) }} ms · min {{ number_format($min, 1) }} ms · max {{ number_format($max, 1) }} ms</p>
            @else
                <div class="mt-4"><x-empty-state title="No samples yet" message="Start Model Preview to collect latency samples." /></div>
            @endif
        </div>

        <!-- Detections per hour -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Detections Over Time</h3>
            <p class="text-xs text-gray-400">Detections per hour, today</p>
            @php $hMax = max(1, max($hourly)); @endphp
            @if (array_sum($hourly) > 0)
                <div class="mt-4 flex h-24 items-end gap-[3px]">
                    @foreach ($hourly as $hh => $count)
                        <div class="flex-1 rounded-t {{ $count > 0 ? 'bg-brand-500' : 'bg-gray-100 dark:bg-gray-700' }}" style="height: {{ max(4, $count / $hMax * 100) }}%" title="{{ str_pad($hh, 2, '0', STR_PAD_LEFT) }}:00 — {{ $count }}"></div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-[11px] text-gray-400">
                    <span>00</span><span>06</span><span>12</span><span>18</span><span>23</span>
                </div>
            @else
                <div class="mt-4"><x-empty-state title="No data" message="No detections today yet." /></div>
            @endif
        </div>
    </div>

    <!-- Training artifacts (real files only) -->
    <div class="card p-5">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="font-bold text-gray-900 dark:text-white">Training Artifacts</h3>
                <p class="text-xs text-gray-400">Real Ultralytics outputs colocated with the active model</p>
            </div>
        </div>
        @php $foundAny = collect($artifactChecklist)->contains(fn ($a) => $a['found']); @endphp
        @if (! $foundAny)
            <div class="rounded-xl bg-gray-50 p-4 text-sm text-gray-500 dark:bg-gray-700/40 dark:text-gray-300">
                Training artifacts unavailable for this model.
                @if (count($artifactFiles) > 0)
                    <span class="mt-1 block text-xs text-gray-400">Other files present in the model directory are listed below.</span>
                @endif
            </div>
        @endif
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($artifactChecklist as $item)
                @if ($item['found'])
                    <x-status-badge color="green" :label="$item['name']" />
                @else
                    <x-status-badge color="gray" :label="$item['name'].' — missing'" />
                @endif
            @endforeach
        </div>
        @if (count($artifactFiles) > 0)
            <div class="mt-4 overflow-hidden rounded-xl border border-gray-100 dark:border-gray-700">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                            <th class="px-4 py-2 font-semibold">File</th>
                            <th class="px-4 py-2 font-semibold">Size</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($artifactFiles as $file)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs text-gray-700 dark:text-gray-200">{{ $file['dir'] }}/{{ $file['name'] }}</td>
                                <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">{{ number_format($file['size'] / 1024, 1) }} KB</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Recent predictions -->
    <div class="card overflow-hidden">
        <div class="border-b border-gray-100 p-5 dark:border-gray-700">
            <h3 class="font-bold text-gray-900 dark:text-white">Recent Predictions</h3>
            <p class="text-xs text-gray-400">Preview snapshots (this session) and real detection records</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                        <th class="px-5 py-3 font-semibold">Time</th>
                        <th class="px-5 py-3 font-semibold">Class</th>
                        <th class="px-5 py-3 font-semibold">Confidence</th>
                        <th class="px-5 py-3 font-semibold">Inference Time</th>
                        <th class="px-5 py-3 font-semibold">Pick Zone</th>
                        <th class="px-5 py-3 font-semibold">Mode</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse (array_reverse($previewLog) as $row)
                        <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $row['at'] }}</td>
                            <td class="whitespace-nowrap px-5 py-3 font-mono text-xs font-bold text-gray-900 dark:text-gray-100">{{ $row['class'] ?? '—' }}</td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ number_format((float) ($row['confidence'] ?? 0), 1) }}%</td>
                            <td class="whitespace-nowrap px-5 py-3 text-gray-600 dark:text-gray-300">{{ $row['latency_ms'] !== null ? number_format((float) $row['latency_ms'], 1).' ms' : '—' }}</td>
                            <td class="px-5 py-3">
                                @if ($row['pick_zone'])
                                    <x-status-badge color="green" label="Valid" />
                                @else
                                    <x-status-badge color="gray" label="Outside" />
                                @endif
                            </td>
                            <td class="px-5 py-3"><x-status-badge color="blue" label="Preview" /></td>
                        </tr>
                    @empty
                        @if (count($recentDetections) === 0)
                            <tr>
                                <td colspan="6"><x-empty-state title="No predictions yet" message="Start Model Preview or run sorting inference to populate this log." /></td>
                            </tr>
                        @endif
                    @endforelse
                    @foreach ($recentDetections as $d)
                        <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $d->detected_at?->format('H:i:s') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-5 py-3 font-mono text-xs font-bold text-gray-900 dark:text-gray-100">{{ strtoupper($d->label ?? $d->color ?? '—') }}</td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ number_format((float) $d->confidence, 1) }}%</td>
                            <td class="px-5 py-3 text-gray-400">—</td>
                            <td class="px-5 py-3">
                                @if ($d->in_pick_zone === null)
                                    <span class="text-gray-400">—</span>
                                @elseif ($d->in_pick_zone)
                                    <x-status-badge color="green" label="Valid" />
                                @else
                                    <x-status-badge color="gray" label="Outside" />
                                @endif
                            </td>
                            <td class="px-5 py-3"><x-status-badge color="gray" label="Sorting" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
