<?php

namespace App\Livewire\Sorting;

use App\Livewire\Livewire\Component;
use App\Models\SortingEvent;
use App\Models\SortingSession;

#[Auth]
#[Layout('layouts.app', ['title' => 'Sorting Events')]
class Events extends Component
{
    public $events = [];
    public $statusFilter = 'all';
    public $colorFilter = 'all';
    public $page = 1;
    public $perPage = 20;

    public function mount()
    {
        $this->loadEvents();
    }

    public function loadEvents()
    {
        $query = SortingEvent::with('detection');
        
        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }
        if ($this->colorFilter !== 'all') {
            $query->where('color', $this->colorFilter);
        }
        
        $this->events = $query->latest('detected_at')
            ->forPage($this->page, $this->perPage)
            ->get();
    }

    public function render()
    {
        return view('livewire.sorting.events', [
            'events' => $this->events,
            'statuses' => SortingEvent::STATUSES,
            'colors' => ['green', 'yellow', 'red', 'all'],
        ]);
    }
}