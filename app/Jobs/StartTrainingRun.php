<?php

namespace App\Jobs;

use App\Models\Annotation;
use App\Models\SystemLog;
use App\Models\TrainingRun;
use App\Services\MlClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gathers the approved annotation dataset and hands the training job off to the
 * ML service. Runs on the queue so the Training screen returns instantly; the
 * run row is then advanced by the service's progress callbacks.
 */
class StartTrainingRun implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $runId) {}

    public function handle(MlClient $ml): void
    {
        $run = TrainingRun::find($this->runId);

        if (! $run || $run->status !== 'queued') {
            return;
        }

        // Split approved annotations ~80/20 into train/val by row order.
        $approved = Annotation::where('status', 'approved')
            ->whereNotNull('image_path')
            ->get(['image_path', 'label', 'bbox']);

        $valEvery = 5; // every 5th sample goes to validation
        $dataset = $approved->values()->map(function (Annotation $a, int $i) use ($valEvery) {
            return [
                'image_path' => $a->image_path,
                'label' => $a->label,
                'bbox' => $a->bbox,
                'split' => ($i + 1) % $valEvery === 0 ? 'val' : 'train',
            ];
        })->all();

        $run->update([
            'status' => 'exporting',
            'dataset_train' => collect($dataset)->where('split', 'train')->count(),
            'dataset_val' => collect($dataset)->where('split', 'val')->count(),
            'started_at' => now(),
        ]);

        $accepted = $ml->startTraining($run, $dataset);

        if (! $accepted) {
            $run->update([
                'status' => 'failed',
                'error' => 'ML service did not accept the training job (is it running?).',
                'finished_at' => now(),
            ]);

            SystemLog::create([
                'level' => 'error',
                'source' => 'ai',
                'message' => "Training run {$run->name} could not start.",
                'context' => ['run_id' => $run->id],
                'logged_at' => now(),
            ]);

            return;
        }

        SystemLog::create([
            'level' => 'info',
            'source' => 'ai',
            'message' => "Training run {$run->name} handed off to ML service ({$run->dataset_train} train / {$run->dataset_val} val).",
            'context' => ['run_id' => $run->id],
            'logged_at' => now(),
        ]);
    }
}
