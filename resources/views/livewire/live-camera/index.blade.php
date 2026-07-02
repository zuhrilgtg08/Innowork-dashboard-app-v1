<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Live Camera</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Pemantauan konveyor &amp; deteksi QC secara langsung.</p>
        </div>
        <span class="inline-flex items-center gap-2 rounded-full bg-green-100 px-3 py-1.5 text-xs font-semibold text-green-700 dark:bg-green-500/15 dark:text-green-400">
            <span class="relative flex h-2 w-2">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-500 opacity-75"></span>
                <span class="relative inline-flex h-2 w-2 rounded-full bg-green-500"></span>
            </span>
            Streaming
        </span>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Camera grid -->
        <div class="lg:col-span-2">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @forelse ($cameras as $cam)
                    <button wire:click="$set('camera', '{{ $camera === $cam->camera ? '' : $cam->camera }}')"
                            class="card overflow-hidden text-left transition hover:ring-2 hover:ring-brand-500/40 {{ $camera === $cam->camera ? 'ring-2 ring-brand-500' : '' }}">
                        <div class="relative flex aspect-video items-center justify-center bg-gray-900">
                            <svg class="h-10 w-10 text-gray-600" fill="none" viewBox="0 0 24 24" stroke-width="1.4" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                            <span class="absolute left-2 top-2 inline-flex items-center gap-1.5 rounded bg-black/60 px-2 py-1 text-[10px] font-semibold uppercase tracking-wider text-white">
                                <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> LIVE
                            </span>
                        </div>
                        <div class="flex items-center justify-between p-4">
                            <div>
                                <p class="font-bold text-gray-900 dark:text-white">{{ $cam->camera }}</p>
                                <p class="text-xs text-gray-400">{{ $cam->conveyor }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ number_format($cam->total) }}</p>
                                <p class="text-[11px] text-gray-400">scan / 24 jam</p>
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="card col-span-full p-10 text-center text-gray-400">Tidak ada kamera aktif.</div>
                @endforelse
            </div>
        </div>

        <!-- Detection feed -->
        <div class="card flex flex-col">
            <div class="border-b border-gray-100 p-4 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white">Detection Feed</h3>
                <p class="text-xs text-gray-400">{{ $camera ?: 'Semua kamera' }}</p>
            </div>
            <div class="scrollbar-thin flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
                @forelse ($feed as $item)
                    <div class="flex items-center gap-3 px-4 py-3">
                        <x-status-badge :color="$item->statusColor()" :label="$item->statusLabel()" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">{{ $item->product?->name ?? $item->code }}</p>
                            <p class="text-xs text-gray-400">{{ $item->camera }} &middot; {{ $item->detected_at?->diffForHumans() }}</p>
                        </div>
                        <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ number_format((float) $item->confidence * 100) }}%</span>
                    </div>
                @empty
                    <div class="p-10 text-center text-gray-400">Belum ada deteksi.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
