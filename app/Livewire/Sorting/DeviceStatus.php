<?php

namespace App\Livewire\Sorting;

use App\Models\ArmStatus;
use App\Models\SortingEvent;
use App\Models\SortingSession;
use App\Services\ArmMqttService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Device Status'])]
class DeviceStatus extends Component
{
    public $armStatus;

    public $mlHealth;

    public $mqttOnline = false;

    public $hardwareMode;

    public $demoControlsVisible = false;

    public $counters = ['green' => 0, 'yellow' => 0, 'red' => 0];

    public function mount()
    {
        $this->armStatus = ArmStatus::current();
        $this->mlHealth = $this->checkMlHealth();
        $this->refreshMqtt();
        $this->hardwareMode = config('services.sorting.hardware_mode', 'real');
        $this->updateDemoVisibility();
        $this->updateCounters();
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

    public function updateDemoVisibility()
    {
        $this->demoControlsVisible = config('services.sorting.mock_hardware', false);
    }

    /**
     * Slow poll target: MQTT broker reachability (cached; a connect per
     * poll would be wasteful).
     */
    public function refreshMqtt()
    {
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

    public function render()
    {
        return view('livewire.sorting.device-status', [
            'armStatus' => $this->armStatus,
            'mlHealth' => $this->mlHealth,
            'mqttOnline' => $this->mqttOnline,
            'hardwareMode' => $this->hardwareMode,
            'demoControlsVisible' => $this->demoControlsVisible,
            'counters' => $this->counters,
        ]);
    }
}
