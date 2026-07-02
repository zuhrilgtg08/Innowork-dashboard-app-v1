@php
    $statusColors = ['active' => 'green', 'inactive' => 'amber', 'archived' => 'gray'];
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Product</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ number_format($total) }} produk pada katalog conveyor.</p>
        </div>
        <button class="btn-primary">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            Add Product
        </button>
    </div>

    <div class="card p-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name, code, SKU..." class="field pl-9" />
            </div>
            <select wire:model.live="status" class="field w-full py-2.5 sm:w-56">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="archived">Archived</option>
            </select>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @forelse ($products as $product)
            <div class="card flex flex-col p-5">
                <div class="flex items-start justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" /></svg>
                    </div>
                    <x-status-badge :color="$statusColors[$product->status] ?? 'gray'" :label="ucfirst($product->status)" />
                </div>
                <h3 class="mt-4 font-bold text-gray-900 dark:text-white">{{ $product->name }}</h3>
                <p class="font-mono text-xs text-gray-400">{{ $product->code }}</p>
                <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                    <div>
                        <p class="text-xs text-gray-400">Category</p>
                        <p class="font-medium text-gray-700 dark:text-gray-200">{{ $product->category }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-400">Scanned</p>
                        <p class="font-semibold text-gray-900 dark:text-white">{{ number_format($product->detections_count) }}×</p>
                    </div>
                </div>
            </div>
        @empty
            <div class="card col-span-full p-10 text-center text-gray-400">No products found.</div>
        @endforelse
    </div>

    <div>{{ $products->links() }}</div>
</div>
