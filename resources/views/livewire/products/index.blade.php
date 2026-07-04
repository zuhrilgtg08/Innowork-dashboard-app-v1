@php
    $statusColors = ['active' => 'green', 'inactive' => 'amber', 'archived' => 'gray'];
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Product</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ number_format($total) }} produk pada katalog conveyor.</p>
        </div>
        <button wire:click="create" class="btn-primary">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            Add Product
        </button>
    </div>

    @if ($flash)
        <div class="flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-400">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            {{ $flash }}
        </div>
    @endif

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
            <div class="card flex flex-col overflow-hidden" wire:key="product-{{ $product->id }}">
                <!-- Product photo -->
                <div class="relative aspect-square bg-gray-100 dark:bg-gray-700">
                    @if ($product->image)
                        <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}" class="h-full w-full object-cover" />
                    @else
                        <div class="flex h-full w-full items-center justify-center text-gray-400">
                            <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke-width="1.4" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                        </div>
                    @endif
                    <span class="absolute left-2 top-2"><x-status-badge :color="$statusColors[$product->status] ?? 'gray'" :label="ucfirst($product->status)" /></span>
                    <!-- QR code thumbnail -->
                    @if ($product->qr_path)
                        <div class="absolute bottom-2 right-2 h-14 w-14 rounded-lg bg-white p-1 shadow ring-1 ring-black/5" title="QR: {{ $product->qrPayload() }}">
                            <img src="{{ Storage::url($product->qr_path) }}" alt="QR {{ $product->code }}" class="h-full w-full" />
                        </div>
                    @endif
                </div>

                <div class="flex flex-1 flex-col p-5">
                    <h3 class="font-bold text-gray-900 dark:text-white">{{ $product->name }}</h3>
                    <p class="font-mono text-xs text-gray-400">{{ $product->code }}</p>
                    <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                        <div>
                            <p class="text-xs text-gray-400">Category</p>
                            <p class="font-medium text-gray-700 dark:text-gray-200">{{ $product->category ?? '—' }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-400">Scanned</p>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ number_format($product->detections_count) }}×</p>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-2">
                        @if ($product->qr_path)
                            <a href="{{ Storage::url($product->qr_path) }}" download="{{ $product->code }}-qr.svg" aria-label="Unduh QR {{ $product->code }}"
                               class="flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-2 text-xs font-semibold text-gray-600 transition hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-400 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5ZM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5ZM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5Z" /></svg>
                                QR
                            </a>
                        @endif
                        <button wire:click="edit({{ $product->id }})" aria-label="Edit {{ $product->name }}"
                                class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-700 transition hover:bg-brand-100 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-brand-600/15 dark:text-brand-400 dark:hover:bg-brand-600/25">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                            Edit
                        </button>
                        <button wire:click="confirmDelete({{ $product->id }})" aria-label="Hapus {{ $product->name }}" title="Hapus produk"
                                class="rounded-lg bg-red-50 p-2 text-red-600 transition hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500 dark:bg-red-600/15 dark:hover:bg-red-600/25">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="card col-span-full">
                <x-empty-state title="Belum ada produk" message="Tidak ada produk yang cocok dengan pencarian atau filter. Coba ubah kata kunci atau tambahkan produk baru." />
            </div>
        @endforelse
    </div>

    <div>{{ $products->links() }}</div>

    <!-- Create / Edit modal -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center px-4 py-6">
            <div class="fixed inset-0 bg-gray-900/60" wire:click="closeModal"></div>
            <div class="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800">
                <div class="mb-5 flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $editingId ? 'Edit Product' : 'Add Product' }}</h3>
                        <p class="text-xs text-gray-400">Foto produk susu &amp; QR code dibuat otomatis.</p>
                    </div>
                    <button wire:click="closeModal" aria-label="Tutup" class="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:hover:bg-gray-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <form wire:submit="save" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <!-- Photo uploader -->
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Foto Produk Susu</label>
                        <div class="flex items-center gap-4">
                            <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-700">
                                @if ($photo)
                                    <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full object-cover" />
                                @elseif ($existingImage)
                                    <img src="{{ Storage::url($existingImage) }}" class="h-full w-full object-cover" />
                                @else
                                    <svg class="h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.4" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" /></svg>
                                @endif
                            </div>
                            <div class="flex-1">
                                <input type="file" wire:model="photo" accept="image/*"
                                       class="block w-full text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100 dark:file:bg-brand-600/15 dark:file:text-brand-400" />
                                <p class="mt-1 text-xs text-gray-400">JPG / PNG, maks 2 MB.</p>
                                <p wire:loading wire:target="photo" class="mt-1 text-xs text-brand-500">Mengunggah...</p>
                                @error('photo') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Code</label>
                        <input wire:model="code" type="text" class="field font-mono" placeholder="PRD-00001" />
                        @error('code') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">SKU</label>
                        <input wire:model="sku" type="text" class="field" placeholder="opsional" />
                        @error('sku') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Nama Produk</label>
                        <input wire:model="name" type="text" class="field" placeholder="Susu UHT Full Cream 1L" />
                        @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Kategori</label>
                        <input wire:model="category" type="text" class="field" placeholder="Susu UHT" />
                        @error('category') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Stok</label>
                        <input wire:model="stock" type="number" min="0" class="field" />
                        @error('stock') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Status</label>
                        <select wire:model="productStatus" class="field py-2.5">
                            @foreach ($statuses as $key => $meta)
                                <option value="{{ $key }}">{{ $meta['label'] }}</option>
                            @endforeach
                        </select>
                        @error('productStatus') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Deskripsi</label>
                        <textarea wire:model="description" rows="2" class="field" placeholder="opsional"></textarea>
                        @error('description') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-2 pt-2 sm:col-span-2">
                        <button type="button" wire:click="closeModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Batal</button>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save,photo">
                            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Simpan Perubahan' : 'Tambah Produk' }}</span>
                            <span wire:loading wire:target="save">Menyimpan...</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Delete confirmation -->
    @if ($confirmingDeleteId)
        <div class="fixed inset-0 z-50 flex items-center justify-center px-4 py-6">
            <div class="fixed inset-0 bg-gray-900/60" wire:click="$set('confirmingDeleteId', null)"></div>
            <div class="relative w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-xl dark:bg-gray-800">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/15">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                </div>
                <h3 class="mt-4 text-lg font-bold text-gray-900 dark:text-white">Hapus produk ini?</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Foto &amp; QR code juga akan dihapus.</p>
                <div class="mt-5 flex justify-center gap-2">
                    <button wire:click="$set('confirmingDeleteId', null)" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Batal</button>
                    <button wire:click="delete({{ $confirmingDeleteId }})" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Hapus</button>
                </div>
            </div>
        </div>
    @endif
</div>
