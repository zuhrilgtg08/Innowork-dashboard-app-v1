<div wire:poll.3s="refreshBoard" class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900 dark:text-white">Sorting Dashboard</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Vision Sorting System</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-status-badge color="blue" label="Vision Sorting" />
            @if ($mockHardware)
                <x-status-badge color="amber" label="Hardware Simulation" />
            @else
                <x-status-badge color="green" label="Live Hardware" />
            @endif
            <button wire:click="resetSession" wire:confirm="Reset the active sorting session and start a new one?" class="btn-secondary !py-2 text-sm">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                Reset Sorting Session
            </button>
        </div>
    </div>

    <!-- Primary row: live feed + current detection -->
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-5">
        <!-- ICAM-300 live feed -->
        <div class="card overflow-hidden xl:col-span-3">
            <div class="flex items-center justify-between border-b border-gray-100 p-5 dark:border-gray-700">
                <div>
                    <h3 class="font-bold text-gray-900 dark:text-white">ICAM-300 Live Feed</h3>
                    <p class="text-xs text-gray-400">{{ $cameraStatus['source'] ?? 'Industrial AI Camera' }}</p>
                </div>
                @if ($mlHealth)
                    <x-status-badge color="green" label="Live" />
                @else
                    <x-status-badge color="red" label="Offline" />
                @endif
            </div>
            <div class="relative bg-gray-900">
                @if ($streamUrl)
                    <img src="{{ $streamUrl }}" alt="ICAM-300 live stream"
                        class="aspect-video w-full object-cover"
                        @if (! $mlHealth) style="opacity: 0.45; filter: grayscale(1);" @endif
                    >
                @endif
                @if (! $mlHealth)
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="rounded-xl bg-gray-900/70 px-5 py-4 text-center">
                            <p class="text-sm font-semibold text-white">Camera Stream Unavailable</p>
                            <p class="mt-1 text-xs text-gray-300">ML service offline — check the Python service.</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <!-- Current detection -->
        <div class="card p-5 xl:col-span-2">
            <h3 class="font-bold text-gray-900 dark:text-white">Current Detection</h3>
            <p class="text-xs text-gray-400">Latest sorting event</p>
            @if ($latestEvent)
                @php
                    $tone = ['green' => 'text-green-600 dark:text-green-400', 'yellow' => 'text-amber-600 dark:text-amber-400', 'red' => 'text-red-600 dark:text-red-400'][$latestEvent->color] ?? 'text-gray-600';
                    $armState = strtolower($armStatus?->state ?? 'idle');
                    $pickZone = $latestEvent->detection?->in_pick_zone;
                @endphp
                <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-gray-400">Detected Color</p>
                <p class="text-3xl font-extrabold tracking-tight {{ $tone }}">{{ strtoupper($latestEvent->color) }}</p>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Confidence</dt>
                        <dd class="font-bold text-gray-900 dark:text-white">{{ number_format((float) $latestEvent->confidence, 1) }}%</dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Destination</dt>
                        <dd class="font-mono text-xs font-bold text-gray-900 dark:text-white">{{ $latestEvent->destination }}</dd>
                    </div>
                    <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">Pick Zone</dt>
                        <dd>
                            @if ($pickZone === null)
                                <span class="text-gray-400">—</span>
                            @elseif ($pickZone)
                                <x-status-badge color="green" label="Valid" />
                            @else
                                <x-status-badge color="gray" label="Outside" />
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-gray-500 dark:text-gray-400">Arm</dt>
                        <dd class="font-bold uppercase text-gray-900 dark:text-white">{{ $armState }}</dd>
                    </div>
                </dl>
            @else
                <div class="mt-4">
                    <x-empty-state title="No detections yet" message="Run inference or use the simulation controls in Device Status." />
                </div>
            @endif
        </div>
    </div>

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

    <!-- System health (slow poll) -->
    <div wire:poll.30s="refreshHealth" class="card p-5">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="font-bold text-gray-900 dark:text-white">Sorting Status</h3>
            <span class="text-xs text-gray-400">Health probes refresh every 30s</span>
        </div>
        @php
            $armState = strtolower($armStatus?->state ?? 'idle');
            $espTone = $armState === 'error' ? 'red' : (in_array($armState, ['ready', 'completed'], true) ? 'green' : 'amber');
            $espLabel = $armState === 'error' ? 'Error' : (in_array($armState, ['ready', 'completed'], true) ? 'Ready' : 'Busy');
            $icamConnected = (bool) ($cameraStatus['connected'] ?? false);
        @endphp
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">ML Service</p>
                <p class="mt-1"><x-status-badge :color="$mlHealth ? 'green' : 'red'" :label="$mlHealth ? 'Online' : 'Offline'" /></p>
            </div>
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">ICAM-300</p>
                <p class="mt-1"><x-status-badge :color="$icamConnected ? 'green' : 'red'" :label="$icamConnected ? 'Online' : 'Offline'" /></p>
                @if ($icamConnected && ! empty($cameraStatus['mode']))
                    <p class="mt-1 text-[11px] text-gray-400">{{ $cameraStatus['mode'] }}</p>
                @endif
            </div>
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">MQTT Broker</p>
                <p class="mt-1"><x-status-badge :color="$mqttOnline ? 'green' : 'red'" :label="$mqttOnline ? 'Online' : 'Offline'" /></p>
            </div>
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">ESP32</p>
                <p class="mt-1"><x-status-badge :color="$espTone" :label="$espLabel" /></p>
            </div>
            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Arm</p>
                <p class="mt-1"><x-status-badge color="blue" :label="strtoupper($armState)" /></p>
            </div>
        </div>
    </div>

    <!-- Recent sorting events -->
    <div class="card overflow-hidden">
        <div class="flex flex-col gap-3 border-b border-gray-100 p-5 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="font-bold text-gray-900 dark:text-white">Recent Sorting Events</h3>
            <a href="{{ route('sorting.events') }}" wire:navigate class="text-sm font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400">View all events &rarr;</a>
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
                    @forelse ($recentEvents ?? [] as $event)
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
                            <td colspan="5"><x-empty-state title="No events yet" message="Sorting events will appear here once inference triggers a sort command." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Sorting complete -->
    @if ($sortingComplete)
        <div class="card border-green-200 bg-green-50 p-6 text-center dark:border-green-500/30 dark:bg-green-500/10">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-500/20">
                <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            </div>
            <h3 class="mt-3 text-lg font-extrabold text-green-700 dark:text-green-300">Sorting Complete</h3>
            <p class="mt-1 text-sm text-green-600 dark:text-green-400">All objects have been sorted successfully.</p>
        </div>
    @endif
</div>
