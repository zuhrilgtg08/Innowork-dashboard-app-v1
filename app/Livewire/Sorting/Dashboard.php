<?php

namespace App\Livewire\Sorting;

use App\Models\ArmStatus;
use App\Models\SortingEvent;
use App\Models\SortingSession;
use App\Services\ArmMqttService;
use App\Services\MlClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Sorting Dashboard'])]
class Dashboard extends Component
{
    public $armStatus;

    public $mlHealth;

    public $mqttOnline = false;

    public $cameraStatus;

    public $streamUrl;

    public $recentEvents;

    public $latestEvent;

    public $counters = ['green' => 0, 'yellow' => 0, 'red' => 0];

    public $sortingComplete = false;

    public $hardwareMode = 'real';

    public $mockHardware = false;

    public $demoControlsVisible = false;

    public function mount()
    {
        $this->armStatus = ArmStatus::current();
        $this->hardwareMode = config('services.sorting.hardware_mode', 'real');
        $this->mockHardware = (bool) config('services.sorting.mock_hardware', false);
        // Same-origin proxy: the browser must never resolve the ML service's
        // internal address itself (see routes/web.php ml.camera.*).
        $this->streamUrl = route('ml.camera.stream');
        $this->refreshHealth();
        $this->refreshBoard();
        $this->demoControlsVisible = $this->mockHardware;
    }

    protected function checkMlHealth(): bool
    {
        try {
            $resp = Http::timeout(2)->get(config('services.ml.url').'/health');

            return $resp->successful() && $resp->json('model_loaded') ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fast poll target (DB only): counters, arm state, latest detection,
     * recent events. Health probes (ML/MQTT/camera) refresh separately.
     */
    public function refreshBoard()
    {
        $this->updateCounters();
        $this->armStatus = ArmStatus::current();
        $this->latestEvent = SortingEvent::with('detection')->latest('created_at')->first();
        $this->recentEvents = SortingEvent::with('detection')->latest('created_at')->take(8)->get();
    }

    /**
     * Slow poll target: external health probes (cached so a burst of polls
     * cannot hammer the ML service or the MQTT broker).
     */
    public function refreshHealth()
    {
        $this->mlHealth = Cache::remember(
            'sorting.ml_health', now()->addSeconds(25),
            fn () => $this->checkMlHealth()
        );
        $this->cameraStatus = Cache::remember(
            'sorting.camera_status', now()->addSeconds(25),
            fn () => app(MlClient::class)->cameraStatus()
        );
        $this->mqttOnline = Cache::remember(
            'sorting.mqtt_online', now()->addSeconds(25),
            fn () => app(ArmMqttService::class)->isConnected()
        );
    }

    public function updateCounters()
    {
        $session = SortingSession::current();
        $counts = SortingEvent::countsByColor($session?->id);
        $this->counters = [
            'green' => $counts['green'] ?? 0,
            'yellow' => $counts['yellow'] ?? 0,
            'red' => $counts['red'] ?? 0,
        ];
        $this->sortingComplete = SortingEvent::systemState($session?->id) === 'SORTING_COMPLETE';
    }

    public function resetSession()
    {
        $active = SortingSession::where('status', 'active')->first();
        $active?->update([
            'status' => 'completed',
            'completed_at' => now(),
            'last_reset_at' => now(),
        ]);
        SortingSession::current();
        app(ArmMqttService::class)->publishPayload([
            'action' => 'reset_session',
            'source' => 'demo',
            'timestamp' => now()->toIso8601String(),
        ]);
        $this->updateCounters();
    }

    public function toggleDemoControls()
    {
        $this->demoControlsVisible = ! $this->demoControlsVisible;
    }

    public function simulateComplete()
    {
        if (! $this->demoControlsVisible) {
            return;
        }

        $color = in_array(request('color'), ['green', 'yellow', 'red'], true) ? request('color') : 'green';
        $eventUuid = (string) Str::uuid();

        app(ArmMqttService::class)->publishPayload([
            'action' => 'sort',
            'event_uuid' => $eventUuid,
            'color' => $color,
            'destination' => 'BOWL_'.strtoupper($color),
            'confidence' => 100,
            'source' => 'demo',
        ]);
    }

    public function simulateError()
    {
        if (! $this->demoControlsVisible) {
            return;
        }

        $eventUuid = (string) Str::uuid();

        app(ArmMqttService::class)->publishPayload([
            'action' => 'error',
            'event_uuid' => $eventUuid,
            'detail' => 'motion_failed',
            'source' => 'demo',
        ]);
    }

    public function render()
    {
        return view('livewire.sorting.dashboard', [
            'armStatus' => $this->armStatus,
            'mlHealth' => $this->mlHealth,
            'mqttOnline' => $this->mqttOnline,
            'cameraStatus' => $this->cameraStatus,
            'streamUrl' => $this->streamUrl,
            'hardwareMode' => $this->hardwareMode,
            'mockHardware' => $this->mockHardware,
            'demoControlsVisible' => $this->demoControlsVisible,
            'counters' => $this->counters,
            'sortingComplete' => $this->sortingComplete,
            'latestEvent' => $this->latestEvent,
            'recentEvents' => $this->recentEvents,
        ]);
    }
}
