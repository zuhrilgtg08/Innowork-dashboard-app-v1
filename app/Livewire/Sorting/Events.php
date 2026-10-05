<?php

namespace App\Livewire\Sorting;

use App\Models\SortingEvent;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Sorting Events'])]
class Events extends Component
{
    use WithPagination;

    public $statusFilter = 'all';

    public $colorFilter = 'all';

    public $perPage = 20;

    public function mount()
    {
        $this->resetPage();
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatingColorFilter()
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = SortingEvent::with('detection');

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }
        if ($this->colorFilter !== 'all') {
            $query->where('color', $this->colorFilter);
        }

        $events = $query->latest('created_at')
            ->paginate($this->perPage);

        return view('livewire.sorting.events', [
            'events' => $events,
            'statuses' => SortingEvent::STATUSES,
            'colors' => ['green', 'yellow', 'red', 'all'],
        ]);
    }
}
