<div class="p-6">
    <h2 class="text-2xl font-bold mb-4">Device Status</h2>
    
    <!-- Hardware Mode -->
    <div class="mb-4">
        <p>Hardware Mode: {{ $hardwareMode }}</p>
        @if($demoControlsVisible)
            <div class="p-3 rounded bg-red-100 text-red-800 mb-3">
                <strong>DEMO MODE</strong> — Controls only active in mock mode
            </div>
        @endif
    </div>
    
    <!-- Arm Status -->
    <div class="mb-4">
        <p>Arm Status: <strong>{{ ucfirst($armStatus->state) }}</strong></p>
        @if($armStatus->detail)
            <p>Detail: {{ $armStatus->detail }}</p>
        @endif
    </div>
    
    <!-- ML Health -->
    <div class="mb-4">
        <p>ML Service: <span class="{{ $mlHealth ? 'text-green-500' : 'text-red-500' }}">{{ $mlHealth ? 'ONLINE' : 'OFFLINE' }}</span></p>
    </div>
    
    <!-- Demo Controls (only visible in mock mode) -->
    @if($demoControlsVisible)
        <div class="p-3 rounded bg-red-100 text-red-800 mb-3">
            <strong>DEMO CONTROLS (MOCK MODE ONLY)</strong>
        </div>
        <div class="grid grid-cols-2 mb-4">
            <button wire:click="simulateComplete" class="btn btn-primary w-full">
                SIMULATE COMPLETED
            </button>
            <button wire:click="simulateError" class="btn btn-danger w-full">
                SIMULATE ERROR
            </button>
        </div>
        <button wire:click="resetSession" class="btn btn-success w-full mt-2">
            Reset Session
        </button>
    @endif
    
    <!-- Status Cards -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div>
            <p>MQTT Broker</p>
            <p class="{{ $mlHealth ? 'text-green-500' : 'text-red-500' }}">{{ $mlHealth ? 'ONLINE' : 'OFFLINE' }}</p>
        </div>
        <div>
            <p>ESP32 Status</p>
            <p>{{ $armStatus->state }}</p>
        </div>
        <div>
            <p>Current Event</p>
            <p>{{ $armStatus->detail ?? '—' }}</p>
        </div>
    </div>
    
    <!-- Counters -->
    <div class="grid grid-cols-3 gap-4">
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
    
    <!-- Reset Session -->
    <button wire:click="resetSession" class="mt-6 btn btn-primary">
        Reset Session (start new sorting session)
    </button>
</div>