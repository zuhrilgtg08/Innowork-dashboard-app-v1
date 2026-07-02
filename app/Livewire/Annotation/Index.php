<?php

namespace App\Livewire\Annotation;

use App\Models\Detection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Label & Annotation'])]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    public function updating($name): void
    {
        if ($name === 'status') {
            $this->resetPage();
        }
    }

    public function render()
    {
        // Items awaiting review are the QC failures + recheck queue.
        $queue = Detection::query()
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->whereIn('status', array_merge(Detection::FAILED_STATUSES, ['recheck', 'returned']))
            ->with('product')
            ->latest('detected_at')
            ->paginate(9);

        $pending = Detection::whereIn('status', array_merge(Detection::FAILED_STATUSES, ['recheck']))->count();
        $labelled = Detection::whereNotNull('qr_value')->count();

        return view('livewire.annotation.index', [
            'queue' => $queue,
            'pending' => $pending,
            'labelled' => $labelled,
            'statuses' => Detection::STATUSES,
        ]);
    }
}
