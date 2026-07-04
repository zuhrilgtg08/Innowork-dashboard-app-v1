@props([
    'title' => 'Tidak ada data',
    'message' => null,
])

{{-- Konsisten dipakai di seluruh tabel & grid saat data kosong. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400 dark:bg-gray-700/60 dark:text-gray-500">
        @isset($icon)
            {{ $icon }}
        @else
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m6 4.125 2.25 2.25m0 0 2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>
        @endisset
    </div>
    <p class="mt-4 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $title }}</p>
    @if ($message)
        <p class="mt-1 max-w-sm text-sm text-gray-400 dark:text-gray-500">{{ $message }}</p>
    @endif
    @isset($action)
        <div class="mt-5">{{ $action }}</div>
    @endisset
</div>
