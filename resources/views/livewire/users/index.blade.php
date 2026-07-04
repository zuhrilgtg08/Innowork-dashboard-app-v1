@php
    $roleColors = ['admin' => 'purple', 'supervisor_qc' => 'blue', 'operator' => 'green', 'viewer' => 'gray'];
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Users</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ number_format($total) }} member terdaftar dalam sistem.</p>
        </div>
        <button wire:click="create" class="btn-primary">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            Add User
        </button>
    </div>

    @if ($flash)
        <div class="flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-400">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            {{ $flash }}
        </div>
    @endif

    <div class="card overflow-hidden">
        <!-- Filters -->
        <div class="flex flex-col gap-3 border-b border-gray-100 p-4 dark:border-gray-700 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name or email..." class="field pl-9" />
            </div>
            <select wire:model.live="role" class="field w-full py-2.5 sm:w-56">
                <option value="">All Roles</option>
                @foreach ($roles as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                        <th class="px-5 py-3 font-semibold">User</th>
                        <th class="px-5 py-3 font-semibold">Role</th>
                        <th class="px-5 py-3 font-semibold">Title</th>
                        <th class="px-5 py-3 font-semibold">Status</th>
                        <th class="px-5 py-3 font-semibold">Joined</th>
                        <th class="px-5 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($users as $user)
                        @php $isSoleAdmin = $user->role === 'admin' && $adminCount <= 1; @endphp
                        <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40" wire:key="user-{{ $user->id }}">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700 dark:bg-brand-600/20 dark:text-brand-300">{{ $user->initials() }}</div>
                                    <div>
                                        <p class="font-semibold text-gray-900 dark:text-white">{{ $user->name }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $user->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-1.5">
                                    <x-status-badge :color="$roleColors[$user->role] ?? 'gray'" :label="$user->roleLabel()" />
                                    @if ($isSoleAdmin)
                                        <span title="Administrator tunggal — role terkunci" class="text-gray-400">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                        </span>
                                    @endif
                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ $user->title ?? '—' }}</td>
                            <td class="px-5 py-3">
                                <x-status-badge :color="$user->is_active ? 'green' : 'gray'" :label="$user->is_active ? 'Active' : 'Inactive'" />
                            </td>
                            <td class="px-5 py-3 text-gray-500 dark:text-gray-400">{{ $user->created_at->format('d M Y') }}</td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <button wire:click="edit({{ $user->id }})" title="Edit" aria-label="Edit {{ $user->name }}"
                                            class="rounded-lg p-2 text-gray-400 transition hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:hover:bg-brand-600/15">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    <button wire:click="confirmDelete({{ $user->id }})" title="Delete" aria-label="Hapus {{ $user->name }}" @disabled($isSoleAdmin)
                                            class="rounded-lg p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gray-400 dark:hover:bg-red-600/15">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty-state title="Tidak ada user" message="Tidak ada user yang cocok dengan pencarian atau filter role." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-gray-100 p-4 dark:border-gray-700">
            {{ $users->links() }}
        </div>
    </div>

    <!-- Create / Edit modal -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center px-4 py-6">
            <div class="fixed inset-0 bg-gray-900/60" wire:click="closeModal"></div>
            <div class="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800">
                <div class="mb-5 flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $editingId ? 'Edit User' : 'Add User' }}</h3>
                        <p class="text-xs text-gray-400">{{ $editingId ? 'Perbarui data member.' : 'Tambah member baru ke sistem.' }}</p>
                    </div>
                    <button wire:click="closeModal" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <form wire:submit="save" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Nama</label>
                        <input wire:model="name" type="text" class="field" placeholder="Nama lengkap" />
                        @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Email</label>
                        <input wire:model="email" type="email" class="field" placeholder="nama@sortvision.test" />
                        @error('email') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Role</label>
                            <select wire:model="userRole" class="field py-2.5">
                                @foreach ($roles as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('userRole') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">Title / Jabatan</label>
                            <input wire:model="title" type="text" class="field" placeholder="opsional" />
                            @error('title') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600 dark:text-gray-300">
                            Password {{ $editingId ? '(kosongkan bila tidak diubah)' : '' }}
                        </label>
                        <input wire:model="password" type="password" class="field" placeholder="minimal 8 karakter" />
                        @error('password') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input wire:model="is_active" type="checkbox" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" />
                        Akun aktif
                    </label>
                    @error('is_active') <p class="-mt-2 text-xs text-red-500">{{ $message }}</p> @enderror

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" wire:click="closeModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Batal</button>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Simpan Perubahan' : 'Tambah User' }}</span>
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
                <h3 class="mt-4 text-lg font-bold text-gray-900 dark:text-white">Hapus user ini?</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Tindakan ini tidak dapat dibatalkan.</p>
                <div class="mt-5 flex justify-center gap-2">
                    <button wire:click="$set('confirmingDeleteId', null)" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Batal</button>
                    <button wire:click="delete({{ $confirmingDeleteId }})" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Hapus</button>
                </div>
            </div>
        </div>
    @endif
</div>
