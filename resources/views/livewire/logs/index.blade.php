<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Logs Sistem</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Catatan aktivitas &amp; kejadian sistem.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @foreach ($levels as $key => $color)
                <span class="rounded-full bg-{{ $color }}-100 px-3 py-1.5 text-xs font-semibold text-{{ $color }}-700 dark:bg-{{ $color }}-500/15 dark:text-{{ $color }}-400">
                    {{ ucfirst($key) }}: {{ number_format($counts[$key] ?? 0) }}
                </span>
            @endforeach
        </div>
    </div>

    <div class="card overflow-hidden">
        <!-- Filters -->
        <div class="flex flex-col gap-3 border-b border-gray-100 p-4 dark:border-gray-700 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Cari pesan log..." class="field pl-9" />
            </div>
            <select wire:model.live="level" class="field w-full py-2.5 sm:w-40">
                <option value="">All Levels</option>
                @foreach ($levels as $key => $color)
                    <option value="{{ $key }}">{{ ucfirst($key) }}</option>
                @endforeach
            </select>
            <select wire:model.live="source" class="field w-full py-2.5 sm:w-40">
                <option value="">All Sources</option>
                @foreach ($sources as $src)
                    <option value="{{ $src }}">{{ ucfirst($src) }}</option>
                @endforeach
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                        <th class="px-5 py-3 font-semibold">Level</th>
                        <th class="px-5 py-3 font-semibold">Source</th>
                        <th class="px-5 py-3 font-semibold">Message</th>
                        <th class="px-5 py-3 font-semibold">Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($logs as $log)
                        <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="px-5 py-3"><x-status-badge :color="$log->levelColor()" :label="ucfirst($log->level)" /></td>
                            <td class="px-5 py-3">
                                <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $log->source }}</span>
                            </td>
                            <td class="px-5 py-3 text-gray-700 dark:text-gray-200">{{ $log->message }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-gray-500 dark:text-gray-400">{{ $log->logged_at?->format('d M H:i:s') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-10 text-center text-gray-400">Tidak ada log ditemukan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-gray-100 p-4 dark:border-gray-700">
            {{ $logs->links() }}
        </div>
    </div>
</div>
