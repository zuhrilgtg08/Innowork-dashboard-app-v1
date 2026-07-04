@php
    $user = auth()->user();
    $pageTitle = $pageTitle ?? 'Dashboard';

    // Notifikasi = peringatan sistem 24 jam terakhir (data nyata dari SystemLog).
    $alertLogs = \App\Models\SystemLog::whereIn('level', ['warning', 'error', 'critical'])
        ->where('logged_at', '>=', now()->subDay())
        ->latest('logged_at')
        ->limit(6)
        ->get();
    $alertCount = $alertLogs->count();
@endphp

<header class="sticky top-0 z-20 border-b border-gray-200 bg-white/80 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
    <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
        <!-- Mobile hamburger -->
        <button @click="sidebarOpen = true" aria-label="Buka menu navigasi" class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:hover:bg-gray-800 lg:hidden">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
        </button>

        <!-- Page title -->
        <h1 class="text-lg font-bold text-gray-900 dark:text-white sm:text-xl">{{ $pageTitle }}</h1>

        <div class="ml-auto flex items-center gap-2 sm:gap-3">
            <!-- Search (mencari produk) -->
            <form action="{{ route('products') }}" method="GET" role="search" class="relative hidden md:block">
                <label for="topbar-search" class="sr-only">Cari produk</label>
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input id="topbar-search" name="search" type="search" value="{{ request('search') }}" placeholder="Cari produk..." class="w-52 rounded-xl border-gray-200 bg-gray-50 pl-9 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 lg:w-64" />
            </form>

            <!-- Dark mode toggle -->
            <button
                x-data="{ dark: document.documentElement.classList.contains('dark') }"
                x-on:livewire:navigated.window="dark = document.documentElement.classList.contains('dark')"
                @click="dark = !dark; document.documentElement.classList.toggle('dark', dark); localStorage.setItem('theme', dark ? 'dark' : 'light')"
                :aria-label="dark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap'"
                aria-label="Ganti tema"
                class="rounded-xl border border-gray-200 bg-white p-2.5 text-gray-500 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
            >
                <!-- Sun (shown in dark mode) -->
                <svg x-show="dark" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" style="display:none;" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>
                <!-- Moon (shown in light mode) -->
                <svg x-show="!dark" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" /></svg>
            </button>

            <!-- Notifications -->
            <div class="relative" x-data="{ notifOpen: false }">
                <button @click="notifOpen = !notifOpen"
                        :aria-expanded="notifOpen"
                        aria-label="Notifikasi peringatan sistem"
                        class="relative rounded-xl border border-gray-200 bg-white p-2.5 text-gray-500 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
                    @if ($alertCount > 0)
                        <span class="absolute -right-1 -top-1 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">{{ $alertCount > 9 ? '9+' : $alertCount }}</span>
                    @endif
                </button>

                <!-- Dropdown -->
                <div x-show="notifOpen"
                     x-cloak
                     @click.outside="notifOpen = false"
                     x-transition.origin.top.right
                     class="absolute right-0 mt-2 w-80 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800"
                     style="display:none;">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                        <p class="text-sm font-bold text-gray-900 dark:text-white">Peringatan Sistem</p>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">24 jam</span>
                    </div>
                    <div class="max-h-80 overflow-y-auto">
                        @forelse ($alertLogs as $log)
                            <a href="{{ route('logs') }}" wire:navigate class="flex gap-3 border-b border-gray-50 px-4 py-3 transition last:border-0 hover:bg-gray-50 dark:border-gray-700/60 dark:hover:bg-gray-700/40">
                                <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-{{ $log->levelColor() }}-500"></span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm text-gray-700 dark:text-gray-200">{{ $log->message }}</p>
                                    <p class="mt-0.5 text-xs text-gray-400">{{ ucfirst($log->level) }} · {{ $log->logged_at?->diffForHumans() }}</p>
                                </div>
                            </a>
                        @empty
                            <p class="px-4 py-8 text-center text-sm text-gray-400">Tidak ada peringatan. Semua aman ✓</p>
                        @endforelse
                    </div>
                    <a href="{{ route('logs') }}" wire:navigate class="block border-t border-gray-100 px-4 py-3 text-center text-sm font-semibold text-brand-600 transition hover:bg-gray-50 dark:border-gray-700 dark:text-brand-400 dark:hover:bg-gray-700/40">
                        Lihat semua log
                    </a>
                </div>
            </div>

            <!-- Avatar -->
            <a href="{{ route('profile') }}" wire:navigate title="{{ $user->name }}" aria-label="Profil {{ $user->name }}"
               class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-700 transition hover:ring-2 hover:ring-brand-500 hover:ring-offset-2 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-brand-600/20 dark:text-brand-300 dark:hover:ring-offset-gray-900">
                {{ $user->initials() }}
            </a>
        </div>
    </div>
</header>
