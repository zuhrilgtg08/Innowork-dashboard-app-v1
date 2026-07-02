<?php

namespace App\Livewire\LiveCamera;

use App\Models\Detection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Live Camera'])]
class Index extends Component
{
    #[Url]
    public string $camera = '';

    public function render()
    {
        $cameras = Detection::query()
            ->where('detected_at', '>=', now()->subDay())
            ->selectRaw('camera, conveyor, count(*) as total, max(detected_at) as last_seen')
            ->groupBy('camera', 'conveyor')
            ->orderBy('camera')
            ->get();

        $feed = Detection::query()
            ->when($this->camera, fn ($q) => $q->where('camera', $this->camera))
            ->with('product')
            ->latest('detected_at')
            ->limit(12)
            ->get();

        return view('livewire.live-camera.index', [
            'cameras' => $cameras,
            'feed' => $feed,
        ]);
    }
}
