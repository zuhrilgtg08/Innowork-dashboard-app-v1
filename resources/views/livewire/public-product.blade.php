<div>
    <div class="mb-6 text-center">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-400">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5ZM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5Z" /></svg>
            Verifikasi Produk
        </span>
    </div>

    @if ($product->image)
        <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}"
             class="mx-auto mb-5 h-40 w-40 rounded-2xl object-cover ring-1 ring-black/5" />
    @endif

    <h1 class="text-center text-xl font-extrabold text-gray-900 dark:text-white">{{ $product->name }}</h1>
    <p class="mt-1 text-center text-sm text-gray-500 dark:text-gray-400">
        <span class="font-mono">{{ $product->code }}</span>
        @if ($product->sku)
            · SKU {{ $product->sku }}
        @endif
    </p>

    {{-- Latest QC verdict from the vision line --}}
    <div class="mt-6 rounded-xl border border-gray-100 bg-gray-50 p-5 text-center dark:border-gray-700 dark:bg-gray-800/50">
        <p class="text-xs font-medium uppercase tracking-wider text-gray-400">Status QC Terakhir</p>
        @if ($detection)
            <div class="mt-2 flex justify-center">
                <x-status-badge :color="$detection->statusColor()" :label="$detection->statusLabel()" />
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {{ $detection->detected_at?->translatedFormat('d M Y H:i') }}
                @if ($detection->confidence)
                    · confidence {{ number_format((float) $detection->confidence, 1) }}%
                @endif
            </p>
            @if ($detection->camera)
                <p class="mt-1 text-xs text-gray-400">{{ $detection->camera }}@if ($detection->conveyor) · {{ $detection->conveyor }}@endif</p>
            @endif
        @else
            <p class="mt-2 text-sm font-medium text-gray-500 dark:text-gray-400">Belum ada scan QC untuk produk ini.</p>
        @endif
    </div>

    <p class="mt-6 text-center text-xs text-gray-400">Dipindai via {{ config('app.name', 'SortVision') }} QC Vision</p>
</div>
