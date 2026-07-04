@php
    $roleColors = ['admin' => 'purple', 'supervisor_qc' => 'blue', 'operator' => 'green', 'viewer' => 'gray'];
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Roles &amp; Permission</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Hak akses tiap peran terhadap modul sistem.</p>
        </div>
    </div>

    @if ($saved)
        <div class="flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700 dark:border-green-500/30 dark:bg-green-500/10 dark:text-green-400">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            Permission untuk <span class="font-semibold">{{ $roles[$saved] ?? $saved }}</span> berhasil diperbarui.
        </div>
    @endif

    <!-- Role summary cards -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($roles as $key => $label)
            <div class="card p-5 {{ $role === $key ? 'ring-2 ring-brand-500' : '' }}">
                <div class="flex items-start justify-between">
                    <x-status-badge :color="$roleColors[$key] ?? 'gray'" :label="$label" />
                    <span class="text-2xl font-extrabold text-gray-900 dark:text-white">{{ number_format($counts[$key] ?? 0) }}</span>
                </div>
                <p class="mt-3 text-xs text-gray-400">member dengan peran ini</p>
                <div class="mt-4 flex gap-2">
                    <a href="{{ route('roles', ['role' => $key]) }}" wire:navigate aria-label="Lihat permission {{ $label }}"
                       class="flex-1 rounded-lg bg-gray-100 py-2 text-center text-xs font-semibold text-gray-600 transition hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-400 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                        View
                    </a>
                    <button wire:click="edit('{{ $key }}')" aria-label="Edit permission {{ $label }}"
                            class="flex-1 rounded-lg bg-brand-50 py-2 text-center text-xs font-semibold text-brand-700 transition hover:bg-brand-100 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-brand-600/15 dark:text-brand-400 dark:hover:bg-brand-600/25">
                        Edit
                    </button>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Permission matrix -->
    <div class="card overflow-hidden">
        <div class="border-b border-gray-100 p-4 dark:border-gray-700">
            <h3 class="font-bold text-gray-900 dark:text-white">Permission Matrix</h3>
            <p class="mt-1 text-xs text-gray-400">
                @if ($role)
                    Menampilkan peran: <span class="font-semibold text-brand-600 dark:text-brand-400">{{ $roles[$role] ?? $role }}</span>
                    &middot; <a href="{{ route('roles') }}" wire:navigate class="underline">tampilkan semua</a>
                @else
                    Full = akses penuh, Write = ubah data, Read = lihat saja, None = tanpa akses.
                @endif
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                        <th class="px-5 py-3 font-semibold">Module</th>
                        @foreach ($roles as $key => $label)
                            @if (! $role || $role === $key)
                                <th class="px-5 py-3 text-center font-semibold">{{ $label }}</th>
                            @endif
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($modules as $module)
                        <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="px-5 py-3 font-medium text-gray-800 dark:text-gray-200">{{ $module }}</td>
                            @foreach ($roles as $key => $label)
                                @if (! $role || $role === $key)
                                    @php $level = $matrix[$key][$module] ?? '-'; $meta = $access[$level]; @endphp
                                    <td class="px-5 py-3 text-center">
                                        <x-status-badge :color="$meta['color']" :label="$meta['label']" />
                                    </td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit modal -->
    @if ($editingRole)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div wire:click="cancel" class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm"></div>

            <div class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-gray-800">
                <div class="flex items-center justify-between border-b border-gray-100 p-5 dark:border-gray-700">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Edit Permission</h3>
                        <p class="text-xs text-gray-400">Peran: <span class="font-semibold text-brand-600 dark:text-brand-400">{{ $roles[$editingRole] ?? $editingRole }}</span></p>
                    </div>
                    <button wire:click="cancel" aria-label="Tutup" class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:hover:bg-gray-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="scrollbar-thin max-h-[60vh] space-y-2 overflow-y-auto p-5">
                    @foreach ($modules as $module)
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $module }}</span>
                            <select wire:model="draft.{{ $module }}" class="field w-40 py-2">
                                @foreach ($access as $value => $meta)
                                    <option value="{{ $value }}">{{ $meta['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    @error('draft.*') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-100 p-5 dark:border-gray-700">
                    <button wire:click="cancel" type="button" class="btn-secondary">
                        Cancel
                    </button>
                    <button wire:click="save" type="button" class="btn-primary">
                        <span wire:loading.remove wire:target="save">Save Changes</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
