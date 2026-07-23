@php
    $statusColors = ['active' => 'green', 'inactive' => 'amber'];
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Categories</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ number_format($total) }} kategori produk susu.</p>
        </div>
        @if (auth()->user()->canWrite('Categories'))
        <button wire:click="create" class="btn-primary">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            Add Category
        </button>
        @endif
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
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name, description..." class="field pl-9" />
            </div>
            <select wire:model.live="status" class="field w-full py-2.5 sm:w-56">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @forelse ($categories as $category)
            <div class="card flex flex-col overflow-hidden" wire:key="category-{{ $category->id }}">
                <!-- Category photo -->
                <div class="relative aspect-square bg-gray-100 dark:bg-gray-700">
                    @if ($category->image)
                        <img src="{{ $category->imageUrl() }}" alt="{{ $category->name }}" class="h-full w-full object-cover" />
                    @else
                        <div class="flex h-full w-full items-center justify-center text-gray-400">
                            <svg class="h-12 w-12" fill="none" viewBox="0 0 24 24" stroke-width="1.4" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                        </div>
                    @endif
                    <span class="absolute left-2 top-2"><x-status-badge :color="$statusColors[($category->is_active ? 'active' : 'inactive')] ?? 'gray'" :label="$category->is_active ? 'Active' : 'Inactive'" /></span>
                    @if ($category->sort_order > 0)
                        <span class="absolute right-2 top-2 text-xs font-semibold text-white bg-black/50 px-1.5 py-0.5 rounded">#{{ $category->sort_order }}</span>
                    @endif
                </div>

                <div class="flex flex-1 flex-col p-5">
                    <h3 class="font-bold text-gray-900 dark:text-white">{{ $category->name }}</h3>
                    @if ($category->description)
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 line-clamp-2">{{ $category->description }}</p>
                    @endif
                    <div class="mt-4 flex items-center justify-between border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                        <div>
                            <p class="text-xs text-gray-400">Products</p>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $category->products_count ?? 0 }} produk</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-400">Sort Order</p>
                            <p class="font-semibold text-gray-900 dark:text-white">{{ $category->sort_order }}</p>
                        </div>
                    </div>
                    @if (auth()->user()->canWrite('Categories'))
                    <div class="mt-4 flex items-center gap-2">
                        <button wire:click="edit({{ $category->id }})" aria-label="Edit {{ $category->name }}"
                                class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-700 transition hover:bg-brand-100 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-brand-600/15 dark:text-brand-400 dark:hover:bg-brand-600/25">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                            Edit
                        </button>
                        <button wire:click="confirmDelete({{ $category->id }})" aria-label="Hapus {{ $category->name }}" title="Hapus kategori"
                                class="rounded-lg bg-red-50 p-2 text-red-600 transition hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500 dark:bg-red-600/15 dark:hover:bg-red-600/25">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="card col-span-full">
                <x-empty-state title="Belum ada kategori" message="Tidak ada kategori yang cocok dengan pencarian atau filter. Coba ubah kata kunci atau tambahkan kategori baru." />
            </div>
        @endforelse
    </div>

    <div>{{ $categories->links() }}</div>

    <!-- Create / Edit modal -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center px-4 py-6">
            <div class="fixed inset-0 bg-gray-900/60" wire:click="closeModal"></div>
            <div class="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800">
                <div class="mb-5 flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $editingId ? 'Edit Category' : 'Add Category' }}</h3>
                        <p class="text-xs text-gray-400">Kategori produk susu untuk katalog conveyor.</p>
                    </div>
                    <button wire:click="closeModal" aria-label="Tutup" class="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:hover:bg-gray-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <form wire:submit="save" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <!-- Photo uploader -->
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Foto Kategori</label>
                        <div class="flex items-center gap-4">
                            <div class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-700">
                                @if ($photo)
                                    <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full object-cover" />
                                @elseif ($existingImage)
                                    <img src="{{ str_starts_with($existingImage, 'assets/') ? asset($existingImage) : Storage::url($existingImage) }}" class="h-full w-full object-cover" />
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

                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Nama Kategori</label>
                        <input wire:model="name" type="text" class="field" placeholder="Contoh: Susu UHT, Susu Segar, Yogurt" />
                        @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Deskripsi</label>
                        <textarea wire:model="description" rows="2" class="field" placeholder="opsional"></textarea>
                        @error('description') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Sort Order</label>
                        <input wire:model="sort_order" type="number" min="0" class="field" />
                        @error('sort_order') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Status</label>
                        <select wire:model="is_active" class="field py-2.5">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                        @error('is_active') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-2 pt-2 sm:col-span-2">
                        <button type="button" wire:click="closeModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Batal</button>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save,photo">
                            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Simpan Perubahan' : 'Tambah Kategori' }}</span>
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
                <h3 class="mt-4 text-lg font-bold text-gray-900 dark:text-white">Hapus kategori ini?</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Foto juga akan dihapus. Produk yang menggunakan kategori ini tidak akan terhapus.</p>
                <div class="mt-5 flex justify-center gap-2">
                    <button wire:click="$set('confirmingDeleteId', null)" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Batal</button>
                    <button wire:click="delete({{ $confirmingDeleteId }})" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Hapus</button>
                </div>
            </div>
        </div>
    @endif
</div>