<div class="p-6">
    <h2 class="text-2xl font-bold mb-4">Sorting Events</h2>
    
    <!-- Filters -->
    <div class="mb-4">
        <select wire:model="statusFilter" class="rounded p-2 bg-slate-800 text-white">
            <option value="all">All Statuses</option>
            @foreach(SortingEvent::STATUSES as $key => $meta)
                <option :($statusFilter === $key ? 'selected' : '') value="{{ $key }}">{{ $meta['label'] }}</option>
            @endforeach
        </select>
        <select wire:model="colorFilter" class="rounded p-2 bg-slate-800 text-white">
            <option value="all">All Colors</option>
            <option value="green">GREEN</option>
            <option value="yellow">YELLOW</option>
            <option value="red">RED</option>
        </select>
    </div>
    
    <table class="min-w-full bg-slate-800 text-white">
        <thead>
            <tr>
                <th>Color</th>
                <th>Label</th>
                <th>Destination</th>
                <th>Status</th>
                <th>Confidence</th>
                <th>Detected At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($events as $event)
                <tr class="border-b bg-slate-700">
                    <td>{{ $event->color }}</td>
                    <td>{{ $event->label ?? '—' }}</td>
                    <td>{{ $event->destination ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $event->status === 'completed' ? 'bg-green-500 text-white' : ($event->status === 'failed' ? 'bg-red-500 text-white' : 'bg-amber-500 text-white') }}">
                            {{ $event->status }}
                        </span>
                    </td>
                    <td>{{ $event->confidence ?? '—' }}</td>
                    <td>{{ $event->detected_at ? $event->detected_at->diffForHumans() : '—' }}</td>
                    <td>
                        <a href="{{ route('sorting-events.show', $event->id) }}" class="text-blue-400 underline">View</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    
    {{-- Pagination links --}}
    {{ $events->links() }}
</div>