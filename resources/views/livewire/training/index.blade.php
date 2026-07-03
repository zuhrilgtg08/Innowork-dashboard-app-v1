@php
    $maxClass = max(1, $dataset->max('count'));
    $activeRun = $runs->first(fn ($r) => $r->isActive());
@endphp

{{-- Poll only while a run is in flight so progress streams in without a manual refresh. --}}
<div class="space-y-6" @if ($activeRun) wire:poll.3s @endif>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Training</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Pelatihan model deteksi &amp; kesiapan dataset.</p>
        </div>
        <div class="flex items-end gap-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Epochs</label>
                <input wire:model="epochs" type="number" min="1" max="20"
                       class="field w-24 py-2" @disabled($activeRun) />
            </div>
            <button wire:click="startRun" wire:loading.attr="disabled" @disabled($activeRun)
                    class="btn-primary disabled:cursor-not-allowed disabled:opacity-60">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" /></svg>
                <span wire:loading.remove wire:target="startRun">{{ $activeRun ? 'Training…' : 'New Training' }}</span>
                <span wire:loading wire:target="startRun">Memulai…</span>
            </button>
        </div>
    </div>

    @if ($flash)
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-400">{{ $flash }}</div>
    @endif
    @error('epochs')
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400">{{ $message }}</div>
    @enderror
    @if ($error)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-400">{{ $error }}</div>
    @endif

    @if ($activeRun)
        <div class="card p-5">
            <div class="flex items-center justify-between text-sm">
                <span class="font-semibold text-gray-900 dark:text-white">{{ $activeRun->name }}</span>
                <span class="font-medium text-gray-500 dark:text-gray-400">
                    {{ $activeRun->statusLabel() }} · epoch {{ $activeRun->current_epoch }}/{{ $activeRun->epochs }} · {{ $activeRun->progress }}%
                </span>
            </div>
            <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                <div class="h-full rounded-full bg-brand-500 transition-all duration-500" style="width: {{ $activeRun->progress }}%"></div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat-card label="Labelled Samples" :value="number_format($labelled)" tone="blue" sub="siap untuk training">
            <x-slot:icon>
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" /></svg>
            </x-slot:icon>
        </x-stat-card>
        <x-stat-card label="Product Classes" :value="number_format($products)" tone="purple" sub="kategori terdaftar">
            <x-slot:icon>
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" /></svg>
            </x-slot:icon>
        </x-stat-card>
        <x-stat-card label="Best Accuracy" :value="$best ? number_format($best, 1).'%' : '—'" tone="green" sub="mAP@50 run terbaik">
            <x-slot:icon>
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
            </x-slot:icon>
        </x-stat-card>
    </div>

    <!-- Per-class training results chart -->
    @php
        // SVG geometry
        $padL = 44; $padR = 16; $padT = 16; $padB = 56;
        $vbW = 760; $vbH = 288;
        $plotW = $vbW - $padL - $padR;
        $plotH = $vbH - $padT - $padB;
        $baseY = $padT + $plotH;
        $groups = max(1, $classMetrics->count());
        $groupW = $plotW / $groups;
        $series = [
            ['key' => 'precision', 'label' => 'Precision', 'fill' => '#6366f1'],
            ['key' => 'recall',    'label' => 'Recall',    'fill' => '#3b82f6'],
            ['key' => 'f1',        'label' => 'F1-score',  'fill' => '#22c55e'],
        ];
        $barAreaW = $groupW * 0.64;
        $barW = $barAreaW / count($series);
        $yFor = fn ($v) => $baseY - ($v / 100) * $plotH;
    @endphp
    <div class="card p-5">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="font-bold text-gray-900 dark:text-white">Per-Class Training Results</h3>
                <p class="text-xs text-gray-400">Precision, recall &amp; F1-score model per kelas defect.</p>
            </div>
            <div class="flex flex-wrap gap-3 text-xs">
                @foreach ($series as $s)
                    <span class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                        <span class="h-2.5 w-2.5 rounded-sm" style="background: {{ $s['fill'] }}"></span>{{ $s['label'] }}
                    </span>
                @endforeach
            </div>
        </div>

        <div class="mt-4 overflow-x-auto">
            <svg viewBox="0 0 {{ $vbW }} {{ $vbH }}" class="h-72 w-full min-w-[640px]" role="img" aria-label="Per-class training metrics">
                <!-- Y gridlines & labels -->
                @foreach ([0, 25, 50, 75, 100] as $tick)
                    @php $ty = $yFor($tick); @endphp
                    <line x1="{{ $padL }}" y1="{{ $ty }}" x2="{{ $vbW - $padR }}" y2="{{ $ty }}"
                          stroke="currentColor" stroke-width="1" class="text-gray-200 dark:text-gray-700" />
                    <text x="{{ $padL - 8 }}" y="{{ $ty + 4 }}" text-anchor="end"
                          class="fill-gray-400" font-size="11">{{ $tick }}</text>
                @endforeach

                <!-- Bars per class -->
                @foreach ($classMetrics as $g => $cm)
                    @php $gx = $padL + $g * $groupW + ($groupW - $barAreaW) / 2; @endphp
                    @foreach ($series as $i => $s)
                        @php
                            $val = (float) $cm[$s['key']];
                            $bx = $gx + $i * $barW;
                            $by = $yFor($val);
                            $bh = $baseY - $by;
                        @endphp
                        <rect x="{{ round($bx, 1) }}" y="{{ round($by, 1) }}" width="{{ round($barW - 3, 1) }}" height="{{ round(max(0, $bh), 1) }}"
                              rx="2" fill="{{ $s['fill'] }}">
                            <title>{{ $cm['label'] }} · {{ $s['label'] }}: {{ $val }}%</title>
                        </rect>
                    @endforeach
                    <!-- X label -->
                    <text x="{{ round($padL + $g * $groupW + $groupW / 2, 1) }}" y="{{ $baseY + 18 }}" text-anchor="middle"
                          class="fill-gray-500 dark:fill-gray-400" font-size="11">{{ $cm['label'] }}</text>
                    <text x="{{ round($padL + $g * $groupW + $groupW / 2, 1) }}" y="{{ $baseY + 34 }}" text-anchor="middle"
                          class="fill-gray-400" font-size="10">{{ number_format($cm['samples']) }} smp</text>
                @endforeach

                <!-- Baseline -->
                <line x1="{{ $padL }}" y1="{{ $baseY }}" x2="{{ $vbW - $padR }}" y2="{{ $baseY }}"
                      stroke="currentColor" stroke-width="1.5" class="text-gray-300 dark:text-gray-600" />
            </svg>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Dataset distribution -->
        <div class="card p-5">
            <h3 class="font-bold text-gray-900 dark:text-white">Dataset Distribution</h3>
            <p class="text-xs text-gray-400">Jumlah sampel per kelas defect.</p>
            <div class="mt-5 space-y-3">
                @foreach ($dataset as $row)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $row['label'] }}</span>
                            <span class="text-gray-400">{{ number_format($row['count']) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-{{ $row['color'] }}-500" style="width: {{ round($row['count'] / $maxClass * 100) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Training runs -->
        <div class="card overflow-hidden lg:col-span-2">
            <div class="border-b border-gray-100 p-4 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white">Training Runs</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                            <th class="px-5 py-3 font-semibold">Model</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold">Accuracy</th>
                            <th class="px-5 py-3 font-semibold">Epochs</th>
                            <th class="px-5 py-3 font-semibold">Finished</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($runs as $run)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $run->name }}</td>
                                <td class="px-5 py-3">
                                    <x-status-badge :color="$run->statusColor()" :label="$run->statusLabel()" />
                                    @if ($run->isActive())
                                        <span class="ml-1 text-xs text-gray-400">{{ $run->progress }}%</span>
                                    @elseif ($run->status === 'failed' && $run->error)
                                        <span class="ml-1 text-xs text-red-400" title="{{ $run->error }}">!</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 font-semibold text-gray-900 dark:text-white">{{ isset($run->metrics['map50']) ? number_format((float) $run->metrics['map50'], 1).'%' : '—' }}</td>
                                <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ $run->current_epoch }}/{{ $run->epochs }}</td>
                                <td class="px-5 py-3 text-gray-500 dark:text-gray-400">{{ $run->finished_at?->diffForHumans() ?? 'in progress' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-400">Belum ada training run. Klik <span class="font-semibold">New Training</span> untuk memulai.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
