@props([
    'label' => '',
    'value' => '',
    'delta' => null,
    'deltaUp' => true,
    'tone' => 'blue',
    'sub' => null,
])

@php
    $tones = [
        'blue'   => 'bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-400',
        'green'  => 'bg-green-50 text-green-600 dark:bg-green-500/15 dark:text-green-400',
        'amber'  => 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
        'red'    => 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-400',
        'orange' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400',
        'purple' => 'bg-purple-50 text-purple-600 dark:bg-purple-500/15 dark:text-purple-400',
    ];
    $toneClass = $tones[$tone] ?? $tones['blue'];
@endphp

<div class="card p-5">
    <div class="flex items-start justify-between">
        <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 items-center justify-center rounded-xl {{ $toneClass }}">
                {{ $icon }}
            </span>
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</span>
        </div>
        @if ($delta)
            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $deltaUp ? 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' }}">
                {{ $delta }}
            </span>
        @endif
    </div>
    <p class="mt-4 text-3xl font-extrabold tracking-tight text-gray-900 dark:text-white">{{ $value }}</p>
    @if ($sub)
        <p class="mt-1 text-xs text-gray-400">{{ $sub }}</p>
    @endif
</div>
