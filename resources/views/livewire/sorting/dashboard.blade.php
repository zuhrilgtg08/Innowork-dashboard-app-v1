@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard-competition.css') }}">
@endpush

<div class="min-h-screen bg-slate-950 text-white">
    <x-app-sidebar :competitionMode="true" />
    
    <div class="p-6">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">SORTVISION AUTOMATIC SORTING</h1>
            @if(config('app.env') !== 'production' && config('services.sorting.mock_hardware'))
                <span class="text-red-500 text-sm font-medium">DEMO MODE — BUKAN FEEDBACK HARDWARE</span>
            @endif
        </div>
        
        <!-- Live Camera -->
        <div class="grid grid-cols-2 gap-6 mb-8">
            <div>
                <h2 class="text-xl font-semibold mb-2">Live ICAM-300 Feed</h2>
                <img src="{{ config('services.ml.stream_url') }}" 
                     class="w-full h-64 object-cover rounded"
                     alt="ICAM stream"
                     @if(!$mlHealth)
                        style="opacity: 0.5; filter: grayscale(1);"
                     @endif
                >
            </div>
            <div>
                <h2 class="text-xl font-semibold mb-2">Current Detection</h2>
                @if(!empty($recentEvents))
                    <div class="p-4 rounded bg-slate-800">
                        <p><strong>Color:</strong> {{ $recentEvents->first()?->color ?? '—' }}</p>
                        <p><strong>Label:</strong> {{ $recentEvents->first()?->label ?? '—' }}</p>
                        <p><strong>Confidence:</strong> {{ $recentEvents->first()?->confidence ?? 0 }}%</p>
                        <p><strong>Destination:</strong> {{ $recentEvents->first()?->destination ?? '—' }}</p>
                        <p><strong>Pick-zone:</strong> {{ $recentEvents->first()?->in_pick_zone ?? false ? 'Yes' : 'No' }}</p>
                        <p><strong>Arm Status:</strong> {{ $armStatus->state }}</p>
                    </div>
                @endif
            </div>
        </div>
        
        <!-- Counters -->
        <div class="grid grid-cols-3 gap-4 mb-8">
            <div class="p-4 rounded bg-slate-800">
                <p class="text-xl font-bold {{ $counters['green'] >= 3 ? 'text-green-600' : '' }}">GREEN {{ $counters['green'] }}/3</p>
            </div>
            <div class="p-4 rounded bg-slate-800">
                <p class="text-xl font-bold {{ $counters['yellow'] >= 3 ? 'text-yellow-600' : '' }}">YELLOW {{ $counters['yellow'] }}/3</p>
            </div>
            <div class="p-4 rounded bg-slate-800">
                <p class="text-xl font-bold {{ $counters['red'] >= 3 ? 'text-red-600' : '' }}">RED {{ $counters['red'] }}/3</p>
            </div>
        </div>
        
        <!-- Status Rings -->
        <div class="grid grid-cols-3 gap-4 mb-8">
            <div>
                <p>MQTT Broker</p>
                <p class="{{ $mlHealth ? 'text-green-500' : 'text-red-500' }}">{{ $mlHealth ? 'ONLINE' : 'OFFLINE' }}</p>
            </div>
            <div>
                <p>ESP32 Status</p>
                <p class="{{ $armStatus->state === 'READY' ? 'text-green-500' : 'text-yellow-500' }}">{{ ucfirst($armStatus->state) }}</p>
            </div>
            <div>
                <p>Arm Status</p>
                <p>{{ ucfirst($armStatus->state) }}</p>
            </div>
        </div>
        
        <!-- Recent Sorting Events -->
        <div>
            <h2 class="text-xl font-semibold mb-4">Recent Sorting Events</h2>
            <ul class="space-y-2">
                @foreach($recentEvents as $event)
                    <li class="p-2 rounded bg-slate-700">
                        <p>{{ $event->color }} – {{ $event->status }}</p>
                        <small>{{ $event->detected_at ? $event->detected_at->diffForHumans() : '—' }}</small>
                    </li>
                @endforeach
            </ul>
        </div>
        
        <!-- SORTING COMPLETE Banner -->
        @if($sortingComplete)
            <div class="p-4 bg-green-500 text-white mt-6 rounded text-center">
                <h3 class="font-bold">SORTING COMPLETE</h3>
                <p>All three bowls are full!</p>
            </div>
        @endif
    </div>
    
    @push('scripts')
    <script>
        // Auto-refresh every 3 seconds
        setTimeout(() => window.location.reload(), 3000);
    @endpush
</div>
@endpush>