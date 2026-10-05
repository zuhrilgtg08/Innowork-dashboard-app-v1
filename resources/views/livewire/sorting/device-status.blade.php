<div wire:poll.5s="updateCounters" class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Device Status</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Arm controller, service health, and sorting counters.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($demoControlsVisible)
                <x-status-badge color="amber" label="Hardware Simulation" />
            @else
                <x-status-badge color="green" label="Live Hardware" />
            @endif
            <span class="text-xs text-gray-400">Mode: {{ $hardwareMode }}</span>
        </div>
    </div>

    <!-- Status cards -->
    <div wire:poll.30s="refreshMqtt" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Arm Status</p>
            <p class="mt-2 text-2xl font-extrabold uppercase tracking-tight text-gray-900 dark:text-white">{{ strtolower($armStatus->state) }}</p>
            @if ($armStatus->detail)
                <p class="mt-1 text-xs text-gray-400">{{ $armStatus->detail }}</p>
            @endif
        </div>
        <div class="card p-5">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">ML Service</p>
            <p class="mt-2"><x-status-badge :color="$mlHealth ? 'green' : 'red'" :label="$mlHealth ? 'Online' : 'Offline'" /></p>
            <p class="mt-1 text-xs text-gray-400">YOLO inference service</p>
        </div>
        <div class="card p-5">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">MQTT Broker</p>
            <p class="mt-2"><x-status-badge :color="$mqttOnline ? 'green' : 'red'" :label="$mqttOnline ? 'Online' : 'Offline'" /></p>
            <p class="mt-1 font-mono text-xs text-gray-400">arm/command · arm/status</p>
        </div>
    </div>

    <!-- Simulation controls (mock hardware only) -->
    @if ($demoControlsVisible)
        <div class="card p-5">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-gray-900 dark:text-white">Simulation Controls</h3>
                    <p class="text-xs text-gray-400">Sends test commands through the MQTT bus (mock hardware only).</p>
                </div>
                <x-status-badge color="amber" label="Simulation" />
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <button wire:click="simulateComplete" class="btn-primary flex-1">Simulate Completed Sort</button>
                <button wire:click="simulateError" class="btn-secondary flex-1">Simulate Error</button>
                <button wire:click="resetSession" class="btn-secondary flex-1">Reset Sorting Session</button>
            </div>
        </div>
    @else
        <div class="card p-5">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="font-bold text-gray-900 dark:text-white">Sorting Session</h3>
                    <p class="text-xs text-gray-400">Complete the active session and start a fresh one.</p>
                </div>
                <button wire:click="resetSession" class="btn-secondary">Reset Sorting Session</button>
            </div>
        </div>
    @endif

    <!-- Counters -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach (['green' => ['tone' => 'green', 'bowl' => 'BOWL_GREEN'], 'yellow' => ['tone' => 'amber', 'bowl' => 'BOWL_YELLOW'], 'red' => ['tone' => 'red', 'bowl' => 'BOWL_RED']] as $color => $meta)
            <x-stat-card
                :label="strtoupper($color)"
                :value="($counters[$color] ?? 0).' / 3'"
                :tone="$meta['tone']"
                :delta="($counters[$color] ?? 0) >= 3 ? 'Complete' : 'Sorting'"
                :deltaUp="($counters[$color] ?? 0) >= 3"
                :sub="$meta['bowl']"
            />
        @endforeach
    </div>
</div>
