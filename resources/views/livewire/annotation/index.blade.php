<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Label &amp; Annotation</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Antrian review &amp; pelabelan hasil deteksi.</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="rounded-full bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">{{ number_format($pending) }} pending</span>
            <span class="rounded-full bg-green-100 px-3 py-1.5 text-xs font-semibold text-green-700 dark:bg-green-500/15 dark:text-green-400">{{ number_format($labelled) }} labelled</span>
        </div>
    </div>

    <div class="card p-4">
        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="$set('status', '')"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $status === '' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300' }}">
                All
            </button>
            @foreach ($statuses as $key => $meta)
                <button wire:click="$set('status', '{{ $key }}')"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $status === $key ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300' }}">
                    {{ $meta['label'] }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($queue as $item)
            <div class="card flex flex-col overflow-hidden">
                <div class="relative flex aspect-video items-center justify-center bg-gray-100 dark:bg-gray-700/50">
                    <svg class="h-10 w-10 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke-width="1.4" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                    <span class="absolute right-2 top-2"><x-status-badge :color="$item->statusColor()" :label="$item->statusLabel()" /></span>
                </div>
                <div class="flex flex-1 flex-col p-4">
                    <p class="font-semibold text-gray-900 dark:text-white">{{ $item->product?->name ?? 'Unknown' }}</p>
                    <p class="font-mono text-xs text-gray-400">{{ $item->code }}</p>
                    <div class="mt-2 flex items-center justify-between text-xs text-gray-400">
                        <span>{{ $item->camera }}</span>
                        <span>{{ $item->detected_at?->diffForHumans() }}</span>
                    </div>
                    <div class="mt-4 flex gap-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                        <button class="flex-1 rounded-lg bg-green-50 py-2 text-xs font-semibold text-green-700 transition hover:bg-green-100 dark:bg-green-500/15 dark:text-green-400">Approve</button>
                        <button class="flex-1 rounded-lg bg-gray-100 py-2 text-xs font-semibold text-gray-600 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300">Relabel</button>
                    </div>
                </div>
            </div>
        @empty
            <div class="card col-span-full p-10 text-center text-gray-400">Antrian kosong — semua sudah dilabeli.</div>
        @endforelse
    </div>

    <div>{{ $queue->links() }}</div>
</div>
