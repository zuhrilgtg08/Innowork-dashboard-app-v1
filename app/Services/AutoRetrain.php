<?php

namespace App\Services;

use App\Jobs\StartTrainingRun;
use App\Models\Annotation;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Models\TrainingRun;

/**
 * Kicks off a fresh training run automatically once enough NEW approved
 * annotations have accumulated since the last completed run. Gated by the
 * Settings "auto_retrain" toggle. Called opportunistically after annotations
 * are approved (see Annotation\Index::storeAnnotation()).
 */
class AutoRetrain
{
    /** Minimum brand-new approved annotations (vs. the last run) to retrain. */
    public const DELTA_THRESHOLD = 50;

    /** Absolute floor of approved annotations before any auto-run. */
    public const MIN_SAMPLES = 20;

    /** Default epochs for an auto-triggered run. */
    public const EPOCHS = 30;

    /**
     * Trigger a run if the conditions are met. Returns the queued run, or null
     * when nothing was started (disabled, a run already active, or not enough
     * new data yet).
     */
    public function maybeTrigger(): ?TrainingRun
    {
        if (! Setting::current()->auto_retrain) {
            return null;
        }

        // Don't stack runs — one in flight at a time.
        if (TrainingRun::whereIn('status', ['queued', 'exporting', 'training'])->exists()) {
            return null;
        }

        $approved = Annotation::where('status', 'approved')->count();
        if ($approved < self::MIN_SAMPLES) {
            return null;
        }

        // Baseline = dataset size of the last completed run (0 if none yet).
        $last = TrainingRun::where('status', 'completed')->latest('id')->first();
        $baseline = $last ? ((int) $last->dataset_train + (int) $last->dataset_val) : 0;

        if (($approved - $baseline) < self::DELTA_THRESHOLD) {
            return null;
        }

        $run = TrainingRun::create([
            'name' => 'auto-retrain-'.(TrainingRun::max('id') + 1),
            'status' => 'queued',
            'epochs' => self::EPOCHS,
            'progress' => 0,
        ]);

        StartTrainingRun::dispatch($run->id);

        SystemLog::create([
            'level' => 'info',
            'source' => 'ai',
            'message' => "Auto-retrain queued: {$approved} approved annotations (+".($approved - $baseline)." since last run).",
            'context' => ['run_id' => $run->id, 'approved' => $approved, 'baseline' => $baseline],
            'logged_at' => now(),
        ]);

        return $run;
    }
}
