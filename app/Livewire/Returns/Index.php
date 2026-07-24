<?php

namespace App\Livewire\Returns;

use App\Models\Detection;
use App\Models\ReturnBatch;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * QC return review: operators/supervisors triage the defect batches the
 * auto-reject workflow ({@see \App\Services\QcWorkflow}) diverted off the line,
 * inspect their detections, and resolve them.
 */
#[Layout('layouts.app', ['title' => 'QC Returns'])]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'open';

    /** Batch currently expanded in the detail modal. */
    public ?int $viewingId = null;

    public function updating($name): void
    {
        if ($name === 'status') {
            $this->resetPage();
        }
    }

    public function view(int $id): void
    {
        $this->viewingId = $id;
    }

    public function closeModal(): void
    {
        $this->viewingId = null;
    }

    public function resolve(int $id): void
    {
        $batch = ReturnBatch::findOrFail($id);
        $batch->resolve(auth()->id());

        $this->closeModal();
        session()->flash('flash', "Return batch #{$batch->id} ditandai selesai.");
    }

    public function render()
    {
        $batches = ReturnBatch::query()
            ->withCount('detections')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(15);

        $viewing = $this->viewingId
            ? ReturnBatch::with(['detections.product', 'resolver'])->find($this->viewingId)
            : null;

        return view('livewire.returns.index', [
            'batches' => $batches,
            'viewing' => $viewing,
            'statuses' => ReturnBatch::STATUSES,
            'openCount' => ReturnBatch::where('status', 'open')->count(),
        ]);
    }
}
