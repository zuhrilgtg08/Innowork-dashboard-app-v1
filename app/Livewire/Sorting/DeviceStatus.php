<?php

namespace App\Livewire\Sorting;

use App\Livewire\Livewire\Component;
use App\Models\ArmStatus;
use App\Models\SortingSession;
use App\Services\ArmMqttService;
use Illuminate\Support\Facades\Http;

#[Auth]
#[Layout('layouts.app', ['title' => 'Device Status')]
class DeviceStatus extends Component
{
    public $armStatus;
    public $mlHealth;
    public $hardwareMode;
    public $demoControlsVisible = false;
    public $simulateComplete = false;
    public $simulateError = false;

    public function mount()
    {
        $this->armStatus = ArmStatus::firstOrCreate(['state' => 'idle']);
        $this->mlHealth = $this->checkMlHealth();
        $this->hardwareMode = config('services.sorting.hardware_mode', 'real');
        $this->updateDemoVisibility();
    }

    protected function checkMlHealth(): bool
    {
        try {
            $resp = Http::timeout(2)->get(config('services.ml.url') . '/health');
            return $resp->successful() && $resp->json('model_loaded') ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function $updateDemoVisibility()
    {
        $this->demoControlsVisible = config('services.sorting.mock_hardware', false);
    }

    public function toggleDemoControls()
    {
        $this->demoControlsVisible = ! $this->demoControlsVisible;
    }

    public function simulateComplete()
    {
        if (! $this->demoControlsVisible) return;
        
        $eventUuid = now()->getKey();
        Http::post(config('services.sorting.api_endpoint') . '/sort/event', [
            'event_uuid' => $eventUuid,
            'type' => 'completed',
            'color' => request('color') ?? 'green',
            'confidence' => 100,
            'metadata' => ['source' => 'demo', 'simulated' => true]
        ]);
        
        $this->simulateComplete = true;
    }

    public function simulateError()
    {
        if (! $this->demoControlsVisible) return;
        
        $eventUuid = now()->getKey();
        Http::post(config('services.sorting.api_endpoint') . '/sort/event', [
            'event_uuid' => $eventUuid,
            'type' => 'error',
            'color' => request('color') ?? 'green',
            'confidence' => 100,
            'metadata' => ['source' => 'demo', 'simulated' => true, 'detail' => 'motion_failed']
        ]);
        
        $this->simulateError = true;
    }

    public function resetSession()
    {
        SortingSession::updateOrCreate(
            ['status' => 'active', 'started_at' => now()],
            ['status' => 'completed', 'completed_at' => now()]
        );
        $this->updateCounters();
    }

    public function render()
    {
        return view('livewire.sorting.device-status', [
            'armStatus' => $this->armStatus,
            'mlHealth' => $this->mlHealth,
            'hardwareMode' => $this->hardwareMode,
            'demoControlsVisible' => $this->demoControlsVisible,
            'simulateComplete' => $this->simulateComplete,
            'simulateError' => $this->simulateError,
        ]);
    }
}