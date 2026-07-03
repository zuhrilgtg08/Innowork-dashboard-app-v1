<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
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
        $s = Setting::current();

        $this->appName = $s->app_name ?? 'SortVision';
        $this->timezone = $s->timezone ?? 'Asia/Jakarta';
        $this->confidenceThreshold = (float) ($s->confidence_threshold ?? 0.85);
        $this->autoRetrain = (bool) $s->auto_retrain;
        $this->emailAlerts = (bool) $s->email_alerts;
        $this->rejectOnDamage = (bool) $s->auto_reject_on_damage;
    }

    protected function rules(): array
    {
        return [
            'appName' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'timezone'],
            'confidenceThreshold' => ['required', 'numeric', 'min:0.5', 'max:1'],
            'autoRetrain' => ['boolean'],
            'emailAlerts' => ['boolean'],
            'rejectOnDamage' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        Setting::current()->update([
            'app_name' => $this->appName,
            'timezone' => $this->timezone,
            'confidence_threshold' => (string) $this->confidenceThreshold,
            'auto_retrain' => $this->autoRetrain,
            'email_alerts' => $this->emailAlerts,
            'auto_reject_on_damage' => $this->rejectOnDamage,
        ]);

        $this->saved = now()->format('H:i:s');
    }

    public function render()
    {
        return view('livewire.settings.index');
    }
}
