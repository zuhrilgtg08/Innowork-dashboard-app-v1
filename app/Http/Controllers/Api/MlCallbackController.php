<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Models\TrainingRun;
use App\Services\MlClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives progress/complete/fail callbacks from the FastAPI training loop and
 * updates the training_runs row that the Training screen polls. This is the only
 * traditional controller in an otherwise Livewire-first app — the caller is a
 * machine (the ML service), not a browser.
 */
class MlCallbackController extends Controller
{
    public function progress(Request $request, TrainingRun $run): JsonResponse
    {
        $data = $request->validate([
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'current_epoch' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'string'],
        ]);

        $run->update([
            'status' => $data['status'] ?? 'training',
            'progress' => $data['progress'],
            'current_epoch' => $data['current_epoch'] ?? $run->current_epoch,
            'started_at' => $run->started_at ?? now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function complete(Request $request, TrainingRun $run): JsonResponse
    {
        $data = $request->validate([
            'metrics' => ['nullable', 'array'],
            'model_path' => ['required', 'string'],
            'dataset_train' => ['nullable', 'integer'],
            'dataset_val' => ['nullable', 'integer'],
        ]);

        $run->update([
            'status' => 'completed',
            'progress' => 100,
            'metrics' => $data['metrics'] ?? null,
            'model_path' => $data['model_path'],
            'dataset_train' => $data['dataset_train'] ?? $run->dataset_train,
            'dataset_val' => $data['dataset_val'] ?? $run->dataset_val,
            'finished_at' => now(),
        ]);

        // Quality gate: only promote the new model to live if it clears the
        // minimum mAP bar. Otherwise keep the previously active model (an
        // automatic rollback) so a bad run can't degrade production.
        $minMap = (float) config('services.ml.min_map', 0);
        $activated = $run->meetsQualityBar($minMap);

        if ($activated) {
            Setting::current()->update(['active_training_run_id' => $run->id]);
            // Best-effort: tell the ML service to hot-reload weights.
            app(MlClient::class)->reloadModel($run->model_path);
        }

        SystemLog::create([
            'level' => $activated ? 'info' : 'warning',
            'source' => 'ai',
            'message' => $activated
                ? "Training run {$run->name} completed and activated (mAP50 ".($run->map50() ?? 'n/a').")."
                : "Training run {$run->name} completed but NOT activated: mAP50 ".($run->map50() ?? 'n/a')." below minimum {$minMap}. Kept previous model.",
            'context' => ['run_id' => $run->id, 'metrics' => $data['metrics'] ?? null, 'activated' => $activated],
            'logged_at' => now(),
        ]);

        return response()->json(['ok' => true, 'activated' => $activated]);
    }

    public function fail(Request $request, TrainingRun $run): JsonResponse
    {
        $data = $request->validate([
            'error' => ['nullable', 'string'],
        ]);

        $run->update([
            'status' => 'failed',
            'error' => $data['error'] ?? 'Unknown error',
            'finished_at' => now(),
        ]);

        SystemLog::create([
            'level' => 'error',
            'source' => 'ai',
            'message' => "Training run {$run->name} failed.",
            'context' => ['run_id' => $run->id, 'error' => $data['error'] ?? null],
            'logged_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
