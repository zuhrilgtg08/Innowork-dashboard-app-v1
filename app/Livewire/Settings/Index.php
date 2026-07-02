<?php

namespace App\Livewire\Settings;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Settings'])]
class Index extends Component
{
    public string $appName = 'SortVision';

    public string $timezone = 'Asia/Jakarta';

    public float $confidenceThreshold = 0.85;

    public bool $autoRetrain = true;

    public bool $emailAlerts = true;

    public bool $rejectOnDamage = true;

    public string $saved = '';

    public function mount(): void
    {
        $this->appName = config('app.name', 'SortVision');
    }

    public function save(): void
    {
        // Persistence layer not wired yet — acknowledge the action in the UI.
        $this->saved = now()->format('H:i:s');
    }

    public function render()
    {
        return view('livewire.settings.index');
    }
}
