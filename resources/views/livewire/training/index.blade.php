@php
    $statusColors = ['completed' => 'green', 'running' => 'blue', 'failed' => 'red'];
    $maxClass = max(1, $dataset->max('count'));
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Training</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Pelatihan model deteksi &amp; kesiapan dataset.</p>
        </div>
        <button class="btn-primary">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" /></svg>
            New Training
        </button>
    </div>

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
        <x-stat-card label="Best Accuracy" value="98.4%" tone="green" sub="yolov8-qc-v4">
            <x-slot:icon>
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
            </x-slot:icon>
        </x-stat-card>
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
                        @foreach ($runs as $run)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $run['name'] }}</td>
                                <td class="px-5 py-3"><x-status-badge :color="$run['color']" :label="ucfirst($run['status'])" /></td>
                                <td class="px-5 py-3 font-semibold text-gray-900 dark:text-white">{{ $run['accuracy'] ? $run['accuracy'].'%' : '—' }}</td>
                                <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ $run['epochs'] }}</td>
                                <td class="px-5 py-3 text-gray-500 dark:text-gray-400">{{ $run['finished']?->diffForHumans() ?? 'in progress' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
