<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Sorting Events</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Every sort command issued to the arm, newest first.</p>
        </div>
    </div>

    <div class="card overflow-hidden">
        <!-- Filters -->
        <div class="flex flex-col gap-3 border-b border-gray-100 p-5 dark:border-gray-700 sm:flex-row sm:items-center">
            <select wire:model.live="statusFilter" class="field w-auto py-2 text-sm">
                <option value="all">All Statuses</option>
                @foreach ($statuses as $key => $meta)
                    <option value="{{ $key }}">{{ $meta['label'] }}</option>
                @endforeach
            </select>
            <select wire:model.live="colorFilter" class="field w-auto py-2 text-sm">
                <option value="all">All Colors</option>
                <option value="green">Green</option>
                <option value="yellow">Yellow</option>
                <option value="red">Red</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                        <th class="px-5 py-3 font-semibold">Time</th>
                        <th class="px-5 py-3 font-semibold">Color</th>
                        <th class="px-5 py-3 font-semibold">Destination</th>
                        <th class="px-5 py-3 font-semibold">Confidence</th>
                        <th class="px-5 py-3 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($events as $event)
                        @php $statusColor = \App\Models\Detection::COMPETITION_STATUSES[$event->status]['color'] ?? 'gray'; @endphp
                        <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            <td class="whitespace-nowrap px-5 py-3 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $event->created_at?->format('H:i:s') ?? '—' }}</td>
                            <td class="px-5 py-3"><x-status-badge :color="$event->color === 'yellow' ? 'amber' : $event->color" :label="strtoupper($event->color)" /></td>
                            <td class="whitespace-nowrap px-5 py-3 font-mono text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $event->destination }}</td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ number_format((float) $event->confidence, 1) }}%</td>
                            <td class="px-5 py-3"><x-status-badge :color="$statusColor" :label="ucfirst(str_replace('_', ' ', $event->status))" /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5"><x-empty-state title="Belum ada event" message="Tidak ada sorting event untuk filter ini." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-gray-100 px-5 py-3 dark:border-gray-700">
            {{ $events->links() }}
        </div>
    </div>
</div>
