<div wire:poll.10s class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">QC Overview</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Realtime deteksi QR &amp; quality control pada conveyor sorting.</p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model.live="range" class="field w-auto py-2 text-sm">
                <option value="today">Today</option>
                <option value="7d">Last 7 days</option>
                <option value="30d">Last 30 days</option>
            </select>
            <button class="btn-primary">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                Export Report
            </button>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-stat-card label="Total Scanned" :value="number_format($stats['total'])" tone="blue" sub="Barang terbaca kamera">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5ZM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5ZM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5ZM16.5 13.5h4.125c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125H16.5A1.125 1.125 0 0 1 15.375 19.5v-4.125c0-.621.504-1.125 1.125-1.125Z" /></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Pass Rate" :value="$stats['passRate'].'%'" tone="green" :delta="$stats['passRate'] >= 90 ? 'Healthy' : 'Watch'" :deltaUp="$stats['passRate'] >= 90" sub="Barang lolos QC">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="QR Unreadable" :value="number_format($stats['unreadable'])" tone="amber" sub="QR rusak / tak terbaca">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Damaged / Scratched" :value="number_format($stats['defective'])" tone="red" sub="Barang cacat / lecet">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Returned / Recheck" :value="number_format($stats['returned'])" tone="orange" sub="Masuk alur return">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" /></svg></x-slot>
        </x-stat-card>

        <x-stat-card label="Throughput" :value="$stats['throughput'].' /min'" tone="purple" :sub="$stats['activeCameras'].' active cameras'">
            <x-slot name="icon"><svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" /></svg></x-slot>
        </x-stat-card>
    </div>

    <!-- Distribution -->
    <div class="card p-5">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 dark:text-white">Status Distribution</h3>
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
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($distribution as $d)
                <div class="flex items-center gap-2 text-sm">
                    <x-status-badge :color="$d['color']" class="!px-1.5 !py-1.5" />
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-white">{{ number_format($d['count']) }}</p>
                        <p class="text-xs text-gray-400">{{ $d['label'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Recent detections -->
    <div class="card overflow-hidden">
        <div class="flex flex-col gap-3 border-b border-gray-100 p-5 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="font-bold text-gray-900 dark:text-white">Recent Detections</h3>
            <div class="flex items-center gap-2">
                <span wire:loading class="text-xs text-brand-500">Refreshing…</span>
                <select wire:model.live="statusFilter" class="field w-auto py-2 text-sm">
                    <option value="">All Types</option>
                    @foreach (\App\Models\Detection::STATUSES as $key => $meta)
                        <option value="{{ $key }}">{{ $meta['label'] }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                        <th class="px-5 py-3 font-semibold">Code</th>
                        <th class="px-5 py-3 font-semibold">Product</th>
                        <th class="px-5 py-3 font-semibold">Camera</th>
                        <th class="px-5 py-3 font-semibold">Line</th>
                        <th class="px-5 py-3 font-semibold">Status</th>
                        <th class="px-5 py-3 font-semibold">Confidence</th>
                        <th class="px-5 py-3 font-semibold">Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($recent as $d)
                        <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $d->code }}</td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ $d->product?->name ?? '-' }}</td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ $d->camera }}</td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ $d->conveyor }}</td>
                            <td class="px-5 py-3"><x-status-badge :color="$d->statusColor()" :label="$d->statusLabel()" /></td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ number_format($d->confidence, 1) }}%</td>
                            <td class="px-5 py-3 text-gray-500 dark:text-gray-400">{{ $d->detected_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7"><x-empty-state title="Belum ada deteksi" message="Tidak ada deteksi untuk rentang waktu atau filter ini." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-gray-100 px-5 py-3 text-right dark:border-gray-700">
            <a href="{{ route('logs') }}" wire:navigate class="text-sm font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400">View all logs &rarr;</a>
        </div>
    </div>
</div>
