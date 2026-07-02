@props([
    'color' => 'gray',
    'label' => null,
])

@php
    $map = [
        'green'  => 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-400',
        'red'    => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400',
        'amber'  => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
        'orange' => 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400',
        'rose'   => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
        'blue'   => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
        'purple' => 'bg-purple-100 text-purple-700 dark:bg-purple-500/15 dark:text-purple-400',
        'gray'   => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    ];
    $classes = $map[$color] ?? $map['gray'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold $classes"]) }}>
    {{ $label ?? $slot }}
</span>
