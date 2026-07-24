<?php

namespace App\Livewire\Detection;

use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Real-Time Object Detection'])]
class Index extends Component
{
    public string $camera = 'CAM-01';

    public string $conveyor = 'LINE-A';

    public array $stats = [
        'fps' => 0,
        'inferenceMs' => 0,
        'objects' => 0,
    ];

    public array $feed = [];

    public function render()
    {
        return view('livewire.detection.index', [
            'camera' => $this->camera,
            'conveyor' => $this->conveyor,
            'stats' => $this->stats,
            'feed' => $this->feed,
        ]);
    }
}
