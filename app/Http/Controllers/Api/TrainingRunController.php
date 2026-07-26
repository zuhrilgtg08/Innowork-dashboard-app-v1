<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesJson;
use App\Http\Controllers\Controller;
use App\Jobs\StartTrainingRun;
use App\Livewire\Training\Index as TrainingScreen;
use App\Models\Annotation;
use App\Models\SystemLog;
use App\Models\TrainingRun;
use App\Services\MlClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Training runs for the mobile app — REST counterpart of
 * `App\Livewire\Training\Index`.
 *
 * `store()` reproduces the web screen's pre-flight checks in the same order
 * (enough approved annotations → ML service reachable → no run already active)
 * so a mobile-triggered run cannot bypass a guard the dashboard enforces.
 */
class TrainingRunController extends Controller
{
    use PaginatesJson;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => $this->perPageRules(),
        ]);

        $runs = TrainingRun::query()
            ->latest()
            ->paginate($this->perPage($validated));

        return $this->paginated($runs, fn (TrainingRun $run) => $this->payload($run));
    }

    public function show(TrainingRun $trainingRun): JsonResponse
    {
        return response()->json(['data' => $this->payload($trainingRun, withMetrics: true)]);
    }

    /**
     * Queue a new YOLO training run over the approved annotation dataset.
     */
    public function store(Request $request, MlClient $ml): JsonResponse
    {
        $data = $request->validate([
            'epochs' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $approved = Annotation::where('status', 'approved')->count();

        if ($approved < TrainingScreen::MIN_SAMPLES) {
            return response()->json([
                'message' => 'Butuh minimal '.TrainingScreen::MIN_SAMPLES.' anotasi disetujui (saat ini '.$approved.'). Labeli dulu di menu Annotation.',
            ], 422);
        }

        if (! $ml->healthy()) {
            return response()->json([
                'message' => 'ML service offline. Jalankan service Python di port 8001 lalu coba lagi.',
            ], 503);
        }

        if (TrainingRun::whereIn('status', ['queued', 'exporting', 'training'])->exists()) {
            return response()->json([
                'message' => 'Sudah ada training yang berjalan. Tunggu sampai selesai.',
            ], 409);
        }

        $run = TrainingRun::create([
            'name' => 'yolov8n-qc-'.(TrainingRun::max('id') + 1),
            'status' => 'queued',
            'epochs' => $data['epochs'],
        ]);

        StartTrainingRun::dispatch($run->id);

        SystemLog::create([
            'level' => 'info',
            'source' => 'ai',
            'message' => "Training run {$run->name} queued ({$approved} samples, {$data['epochs']} epochs).",
            'context' => ['run_id' => $run->id, 'origin' => 'mobile'],
            'logged_at' => now(),
        ]);

        return response()->json([
            'message' => "Training {$run->name} dimulai. Progres akan tampil realtime.",
            'data' => $this->payload($run),
        ], 201);
    }

    /**
     * Dataset summary the training screen shows above the run list.
     */
    public function dataset(): JsonResponse
    {
        $approved = Annotation::where('status', 'approved')->count();

        $perClass = Annotation::where('status', 'approved')
            ->selectRaw('label, count(*) as total')
            ->groupBy('label')
            ->pluck('total', 'label');

        return response()->json([
            'data' => [
                'approved_annotations' => $approved,
                'min_samples' => TrainingScreen::MIN_SAMPLES,
                'can_start' => $approved >= TrainingScreen::MIN_SAMPLES,
                'has_active_run' => TrainingRun::whereIn('status', ['queued', 'exporting', 'training'])->exists(),
                'per_class' => $perClass->map(fn ($total, $label) => [
                    'label' => $label,
                    'count' => (int) $total,
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TrainingRun $run, bool $withMetrics = false): array
    {
        $payload = [
            'id' => $run->id,
            'name' => $run->name,
            'status' => $run->status,
            'status_label' => $run->statusLabel(),
            'status_color' => $run->statusColor(),
            'is_active' => $run->isActive(),
            'progress' => (int) $run->progress,
            'current_epoch' => (int) $run->current_epoch,
            'epochs' => (int) $run->epochs,
            'map50' => $run->map50(),
            'dataset_train' => $run->dataset_train,
            'dataset_val' => $run->dataset_val,
            'error' => $run->error,
            'started_at' => optional($run->started_at)->toIso8601String(),
            'finished_at' => optional($run->finished_at)->toIso8601String(),
            'created_at' => optional($run->created_at)->toIso8601String(),
        ];

        if ($withMetrics) {
            // Metrics are stored on the 0–100 scale (see CLAUDE.md).
            $payload['metrics'] = $run->metrics;
        }

        return $payload;
    }
}
