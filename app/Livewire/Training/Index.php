<?php

namespace App\Livewire\Training;

use App\Jobs\StartTrainingRun;
use App\Models\Annotation;
use App\Models\Detection;
use App\Models\SystemLog;
use App\Models\TrainingRun;
use App\Services\MlClient;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Training'])]
class Index extends Component
{
    /** Minimum approved annotations required before a run can start. */
    public const MIN_SAMPLES = 4;

    public int $epochs = 5;

    public string $flash = '';

    public string $error = '';

    /**
     * Kick off a real YOLO training run over the approved annotation dataset.
     */
    public function startRun(): void
    {
        $this->error = '';
        $this->flash = '';

        $this->validate([
            'epochs' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $approved = Annotation::where('status', 'approved')->count();

        if ($approved < self::MIN_SAMPLES) {
            $this->error = 'At least '.self::MIN_SAMPLES.' approved annotations are required (currently '.$approved.'). Label items in Annotation first.';

            return;
        }

        if (! app(MlClient::class)->healthy()) {
            $this->error = 'ML service is offline. Start the Python service and try again.';

            return;
        }

        if (TrainingRun::whereIn('status', ['queued', 'exporting', 'training'])->exists()) {
            $this->error = 'A training run is already in progress. Please wait for it to finish.';

            return;
        }

        $run = TrainingRun::create([
            'name' => 'yolov8n-qc-'.(TrainingRun::max('id') + 1),
            'status' => 'queued',
            'epochs' => $this->epochs,
        ]);

        StartTrainingRun::dispatch($run->id);

        SystemLog::create([
            'level' => 'info',
            'source' => 'ai',
            'message' => "Training run {$run->name} queued ({$approved} samples, {$this->epochs} epochs).",
            'context' => ['run_id' => $run->id],
            'logged_at' => now(),
        ]);

        $this->flash = "Training {$run->name} started. Progress will appear in real time.";
    }

    public function render()
    {
        $labelled = Annotation::where('status', 'approved')->count();

        // Dataset distribution = approved annotations per class.
        $classCounts = Annotation::where('status', 'approved')
            ->selectRaw('label, count(*) as total')
            ->groupBy('label')
            ->pluck('total', 'label');

        $dataset = collect(Detection::STATUSES)->map(fn ($meta, $key) => [
            'label' => $meta['label'],
            'color' => $meta['color'],
            'count' => (int) ($classCounts[$key] ?? 0),
        ])->values();

        $productClasses = $classCounts->count();

        $runs = TrainingRun::latest()->limit(12)->get();
        $best = $runs->where('status', 'completed')->max(fn ($r) => (float) ($r->metrics['map50'] ?? 0));

        // Per-class chart: prefer the latest completed run's real metrics.
        $latestCompleted = $runs->firstWhere('status', 'completed');
        $classMetrics = $this->classMetrics($latestCompleted, $dataset);

        return view('livewire.training.index', [
            'labelled' => $labelled,
            'products' => $productClasses,
            'dataset' => $dataset,
            'classMetrics' => $classMetrics,
            'runs' => $runs,
            'best' => $best,
        ]);
    }

    /**
     * Build the per-class precision/recall/F1 series for the chart.
     */
    protected function classMetrics(?TrainingRun $run, $dataset): Collection
    {
        $perClass = collect($run?->metrics['per_class'] ?? [])->keyBy('label');

        return collect(Detection::STATUSES)->map(function ($meta, $key) use ($perClass) {
            $m = $perClass->get($key);
            $precision = (float) ($m['precision'] ?? 0);
            $recall = (float) ($m['recall'] ?? 0);
            $f1 = ($precision + $recall) ? round(2 * $precision * $recall / ($precision + $recall), 1) : 0.0;

            return [
                'key' => $key,
                'label' => $meta['label'],
                'color' => $meta['color'],
                'samples' => (int) ($m['samples'] ?? 0),
                'precision' => $precision,
                'recall' => $recall,
                'f1' => $f1,
            ];
        })->values();
    }
}
