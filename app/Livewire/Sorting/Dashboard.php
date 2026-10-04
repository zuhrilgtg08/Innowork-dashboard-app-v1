<?php

namespace App\Livewire\Sorting;

use App\Livewire\Livewire\Component;
use App\Models\SortingEvent;
use App\Models\SortingSession;
use App\Models\Detection;
use App\Models\ArmStatus;
use App\Services\ArmMqttService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

#[Auth]
#[Layout('layouts.app', ['title' => 'Sorting Dashboard')]
class Dashboard extends Component
{
    public $armStatus;
    public $mlHealth;
    public $recentEvents;
    public $counters = ['green' => 0, 'yellow' => 0, 'red' => 0];
    public $sortingComplete = false;
    public $hardwareMode = 'real';
    public $demoControlsVisible = false;

    public function mount()
    {
        $this->armStatus = ArmStatus::firstOrCreate(['state' => 'idle']);
        $this->mlHealth = $this->checkMlHealth();
        $this->hardwareMode = config('services.sorting.hardware_mode', 'real');
        $this->updateCounters();
        $this->recentEvents = SortingEvent::latest()->take(10)->get();
        $this->demoControlsVisible = config('services.sorting.mock_hardware', false);
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

    public function updateCounters()
    {
        $counts = SortingEvent::countsByColor(
            SortingSession::where('status', 'active')->first()?->id
        );
        $this->counters = [
            'green' => $counts['green'] ?? 0,
            'yellow' => $counts['yellow'] ?? 0,
            'red' => $counts['red'] ?? 0,
        ];
        $this->sortingComplete = SortingEvent::systemState($counts) === 'SORTING_COMPLETE';
    }

    public function resetSession()
    {
        SortingSession::updateOrCreate(
            ['status' => 'active', 'started_at' => now()],
            ['status' => 'completed', 'completed_at' => now()]
        );
        $this->updateCounters();
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

    public function render()
    {
        return view('livewire.sorting.dashboard', [
            'armStatus' => $this->armStatus,
            'mlHealth' => $this->mlHealth,
            'hardwareMode' => $this->hardwareMode,
            'demoControlsVisible' => $this->demoControlsVisible,
        ]);
    }
}