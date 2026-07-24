<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">QC Returns</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Batch barang cacat yang dialihkan otomatis dari lini untuk ditinjau.</p>
        </div>
        <span class="rounded-full bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">
            Open: {{ number_format($openCount) }}
        </span>
    </div>

    @if (session('flash'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-400">
            {{ session('flash') }}
        </div>
    @endif

    <div class="card overflow-hidden">
        <!-- Filters -->
        <div class="flex flex-col gap-3 border-b border-gray-100 p-4 dark:border-gray-700 sm:flex-row sm:items-center">
            <select wire:model.live="status" class="field w-full py-2.5 sm:w-48">
                <option value="">Semua Status</option>
                @foreach ($statuses as $key => $meta)
                    <option value="{{ $key }}">{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                        <th class="px-5 py-3 font-semibold">Batch</th>
                        <th class="px-5 py-3 font-semibold">Conveyor</th>
                        <th class="px-5 py-3 font-semibold">Alasan</th>
                        <th class="px-5 py-3 font-semibold">Items</th>
                        <th class="px-5 py-3 font-semibold">Status</th>
                        <th class="px-5 py-3 font-semibold">Dibuat</th>
                        <th class="px-5 py-3 font-semibold text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($batches as $batch)
                        <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="px-5 py-3 font-mono text-xs text-gray-600 dark:text-gray-300">#{{ $batch->id }}</td>
                            <td class="px-5 py-3 text-gray-700 dark:text-gray-200">{{ $batch->conveyor ?? '—' }}</td>
                            <td class="px-5 py-3 text-gray-700 dark:text-gray-200">{{ $batch->reason }}</td>
                            <td class="px-5 py-3">
                                <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ $batch->detections_count }}</span>
                            </td>
                            <td class="px-5 py-3"><x-status-badge :color="$batch->statusColor()" :label="$batch->statusLabel()" /></td>
                            <td class="whitespace-nowrap px-5 py-3 text-gray-500 dark:text-gray-400">{{ $batch->created_at?->format('d M H:i') }}</td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <button wire:click="view({{ $batch->id }})" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-brand-600 transition hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">Detail</button>
                                    @if ($batch->status === 'open')
                                        <button wire:click="resolve({{ $batch->id }})" wire:confirm="Tandai batch #{{ $batch->id }} sebagai selesai?" class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-green-700">Resolve</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state title="Tidak ada return" message="Belum ada batch retur yang cocok dengan filter ini." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-gray-100 p-4 dark:border-gray-700">
            {{ $batches->links() }}
        </div>
    </div>

    <!-- Detail modal -->
    @if ($viewing)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="modal-{{ $viewing->id }}">
            <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" wire:click="closeModal"></div>
            <div class="relative z-10 w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Return Batch #{{ $viewing->id }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $viewing->conveyor ?? '—' }} · {{ $viewing->reason }}</p>
                    </div>
                    <button wire:click="closeModal" class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 dark:hover:bg-gray-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="max-h-[60vh] overflow-y-auto px-6 py-4">
                    @if ($viewing->status === 'resolved')
                        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
                            Diselesaikan {{ $viewing->resolved_at?->format('d M Y H:i') }}{{ $viewing->resolver ? ' oleh '.$viewing->resolver->name : '' }}.
                        </p>
                    @endif
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                                <th class="py-2 pr-3 font-semibold">Kode</th>
                                <th class="py-2 pr-3 font-semibold">Produk</th>
                                <th class="py-2 pr-3 font-semibold">Status</th>
                                <th class="py-2 pr-3 font-semibold">Conf.</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($viewing->detections as $d)
                                <tr>
                                    <td class="py-2 pr-3 font-mono text-xs text-gray-600 dark:text-gray-300">{{ $d->code }}</td>
                                    <td class="py-2 pr-3 text-gray-700 dark:text-gray-200">{{ $d->product?->name ?? '—' }}</td>
                                    <td class="py-2 pr-3"><x-status-badge :color="$d->statusColor()" :label="$d->statusLabel()" /></td>
                                    <td class="py-2 pr-3 text-gray-500 dark:text-gray-400">{{ $d->confidence }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-4 text-center text-gray-400">Tidak ada deteksi.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($viewing->status === 'open')
                    <div class="flex justify-end gap-2 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
                        <button wire:click="closeModal" class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">Tutup</button>
                        <button wire:click="resolve({{ $viewing->id }})" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-green-700">Tandai Selesai</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
