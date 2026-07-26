<?php

namespace App\Livewire\Annotation;

use App\Models\Annotation;
use App\Models\Detection;
use Illuminate\Validation\Rule;
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

    public string $flash = '';

    public function updating($name): void
    {
        if ($name === 'status') {
            $this->resetPage();
        }
    }

    /**
     * Confirm the AI-suggested label as ground truth (adds it to the dataset).
     */
    public function approve(int $detectionId): void
    {
        $detection = Detection::findOrFail($detectionId);

        // 'returned'/'recheck' are workflow states, not visual classes — the AI
        // label can't be confirmed as-is; ask the operator to pick a real class.
        if (! in_array($detection->status, Detection::TRAINABLE_STATUSES, true)) {
            $this->addError('flash', 'Status "'.$detection->statusLabel().'" bukan kelas visual. Relabel ke kelas yang benar dulu.');

            return;
        }

        $this->storeAnnotation($detection, $detection->status, 'ai');
        $this->flash = 'Label disetujui & masuk dataset.';
    }

    /**
     * Correct the label to a different class and feed it back as training data.
     */
    public function relabel(int $detectionId, string $class): void
    {
        $detection = Detection::findOrFail($detectionId);

        validator(['class' => $class], [
            'class' => ['required', Rule::in(Detection::TRAINABLE_STATUSES)],
        ])->validate();

        $this->storeAnnotation($detection, $class, 'human');
        $this->flash = 'Label diperbarui ke "'.Detection::STATUSES[$class]['label'].'".';
    }

    /**
     * Create/refresh the approved annotation for a detection.
     */
    protected function storeAnnotation(Detection $detection, string $label, string $source): void
    {
        // Prefer the captured live frame; fall back to the product photo.
        $imagePath = $detection->frame_path ?: $detection->product?->image;

        if (! $imagePath) {
            $this->addError('flash', 'Tidak ada gambar untuk dilabeli pada item ini.');

            return;
        }

        Annotation::updateOrCreate(
            ['detection_id' => $detection->id],
            [
                'product_id' => $detection->product_id,
                'image_path' => $imagePath,
                'label' => $label,
                'bbox' => null, // whole-frame label (demo)
                'status' => 'approved',
                'source' => $source,
                'confidence' => $detection->confidence,
            ],
        );

        // Opportunistically retrain once enough new labels have accrued.
        app(\App\Services\AutoRetrain::class)->maybeTrigger();
    }

    public function render()
    {
        $annotatedIds = Annotation::whereNotNull('detection_id')->pluck('detection_id');

        // Queue = detections not yet labelled, preferring real captured frames,
        // then the QC failure / recheck backlog.
        $queue = Detection::query()
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->whereNotIn('id', $annotatedIds)
            ->where(fn ($q) => $q->whereNotNull('frame_path')
                ->orWhereIn('status', array_merge(Detection::FAILED_STATUSES, ['recheck', 'returned'])))
            ->with('product')
            ->orderByRaw('frame_path IS NULL')
            ->latest('detected_at')
            ->paginate(9);

        $pending = Detection::whereNotIn('id', $annotatedIds)
            ->where(fn ($q) => $q->whereNotNull('frame_path')
                ->orWhereIn('status', array_merge(Detection::FAILED_STATUSES, ['recheck', 'returned'])))
            ->count();

        $labelled = Annotation::where('status', 'approved')->count();

        // All statuses drive the filter chips; only trainable ones are offered
        // as relabel targets (see the relabel buttons in the view).
        $trainable = collect(Detection::STATUSES)
            ->only(Detection::TRAINABLE_STATUSES);

        return view('livewire.annotation.index', [
            'queue' => $queue,
            'pending' => $pending,
            'labelled' => $labelled,
            'statuses' => Detection::STATUSES,
            'trainable' => $trainable,
        ]);
    }
}
