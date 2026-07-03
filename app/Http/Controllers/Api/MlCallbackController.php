<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Models\TrainingRun;
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

        // Auto-activate the freshly trained model for inference.
        Setting::current()->update(['active_training_run_id' => $run->id]);

        SystemLog::create([
            'level' => 'info',
            'source' => 'ai',
            'message' => "Training run {$run->name} completed and activated.",
            'context' => ['run_id' => $run->id, 'metrics' => $data['metrics'] ?? null],
            'logged_at' => now(),
        ]);

        return response()->json(['ok' => true]);
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
