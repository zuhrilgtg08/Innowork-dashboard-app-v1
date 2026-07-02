<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name', 'SortVision') }}</title>

        <!-- Anti-flicker dark mode: apply theme before first paint & on SPA navigation -->
        @include('layouts.partials.theme')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased text-gray-800 dark:text-gray-100">
        <div
            x-data="{ sidebarOpen: false }"
            class="min-h-screen bg-gray-50 dark:bg-gray-900"
        >
            <!-- Mobile backdrop -->
            <div
                x-show="sidebarOpen"
                x-transition.opacity
                @click="sidebarOpen = false"
                class="fixed inset-0 z-30 bg-gray-900/50 lg:hidden"
                style="display: none;"
            ></div>

            <!-- Sidebar -->
            @include('layouts.partials.sidebar')

            <!-- Main column -->
            <div class="lg:pl-72">
                @include('layouts.partials.topbar', ['pageTitle' => $title ?? 'Dashboard'])

                <main class="px-4 py-6 sm:px-6 lg:px-8">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
