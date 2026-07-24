<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'SortVision') }}</title>

        <!-- Anti-flicker dark mode: apply theme before first paint & on SPA navigation -->
        @include('layouts.partials.theme')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Livewire -->
        @livewireStyles

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="grid min-h-screen lg:grid-cols-2">
            <!-- Branding panel -->
            <div class="relative hidden overflow-hidden bg-brand-700 lg:flex lg:flex-col lg:justify-between lg:p-12">
                <div class="absolute inset-0 bg-gradient-to-br from-brand-600 via-brand-700 to-brand-900"></div>
                <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-24 -left-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>

                <div class="relative flex items-center gap-3 text-white">
                    <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/15 backdrop-blur">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5ZM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5ZM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5ZM13.5 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5Z" /></svg>
                    </div>
                    <span class="text-2xl font-extrabold">{{ config('app.name', 'SortVision') }}</span>
                </div>

                <div class="relative text-white">
                    <h2 class="max-w-md text-3xl font-extrabold leading-tight">AI Visual Quality Control untuk Industri Sorting &amp; Logistik</h2>
                    <p class="mt-4 max-w-md text-brand-100">Deteksi QR code realtime pada conveyor, identifikasi barang cacat/lecet, dan otomatisasi alur return &amp; recheck - semua dalam satu dashboard.</p>
                    <div class="mt-8 flex gap-8">
                        <div><p class="text-3xl font-extrabold">99.2%</p><p class="text-sm text-brand-200">Detection Accuracy</p></div>
                        <div><p class="text-3xl font-extrabold">4×</p><p class="text-sm text-brand-200">Active Cameras</p></div>
                        <div><p class="text-3xl font-extrabold">Realtime</p><p class="text-sm text-brand-200">Multi-Detection</p></div>
                    </div>
                </div>

                <p class="relative text-sm text-brand-200">&copy; {{ date('Y') }} {{ config('app.name', 'SortVision') }}. All rights reserved.</p>
            </div>

            <!-- Form panel -->
            <div class="relative flex items-center justify-center bg-gray-50 px-6 py-12 dark:bg-gray-900">
                <!-- Dark mode toggle -->
                <button
                    x-data="{ dark: document.documentElement.classList.contains('dark') }"
                    x-on:livewire:navigated.window="dark = document.documentElement.classList.contains('dark')"
                    @click="dark = !dark; document.documentElement.classList.toggle('dark', dark); localStorage.setItem('theme', dark ? 'dark' : 'light')"
                    title="Toggle theme"
                    class="absolute right-6 top-6 rounded-xl border border-gray-200 bg-white p-2.5 text-gray-500 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                >
                    <svg x-show="dark" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" style="display:none;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>
                    <svg x-show="!dark" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" /></svg>
                </button>

                    <div class="w-full max-w-md">
                        <a href="/" wire:navigate class="mb-8 flex items-center gap-3 lg:hidden">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 text-white">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5ZM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125v-4.5ZM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125V4.125ZM13.5 14.625c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                            </div>
                            <span class="text-xl font-extrabold text-gray-900 dark:text-white">{{ config('app.name', 'SortVision') }}</span>
                        </a>

                        <div class="card p-8">
                            {{ $slot }}
                        </div>
                    </div>
                </div>

                @livewireScripts
            </div>
        </div>
    </body>
</html>
