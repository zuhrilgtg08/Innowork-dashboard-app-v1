<?php

namespace App\Livewire\ModelEvaluation;

use App\Models\Detection;
use App\Models\Setting;
use App\Services\MlClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Model Evaluation (Vision Sorting · AI Model).
 *
 * Read-only evaluation of the live YOLO model: real model metadata from the
 * ML service, an annotated preview stream, runtime inference graphs, real
 * training artifacts when present, and a prediction log.
 *
 * This page NEVER actuates the robot: the preview path (ml-service
 * preview.py) performs inference only — it publishes no MQTT arm/command
 * and writes no sorting_events. Counters are driven exclusively by the
 * sorting pipeline (POST /infer in sorting mode).
 */
#[Layout('layouts.app', ['title' => 'Model Evaluation'])]
class Index extends Component
{
    /** Model preview stream visible. */
    public bool $previewing = false;

    /** Latest preview snapshot (info panel + log source). */
    public ?array $preview = null;

    /** Current preview-session snapshots, newest last (cap 15). */
    public array $previewLog = [];

    /** Real model metadata from GET /model/info (null when ML offline). */
    public ?array $modelInfo = null;

    public bool $mlOnline = false;

    public string $previewUrl = '';

    public float $confThreshold = 0.85;

    public string $activeModelPath = '';

    public bool $activeModelExists = false;

    public ?string $lastInferenceAt = null;

    /** Real files present under the model artifacts directory. */
    public array $artifactFiles = [];

    /** Expected Ultralytics artifacts with found/not-found flags. */
    public array $artifactChecklist = [];

    /** Live runtime telemetry (summary + latest YOLO result, cached). */
    public ?array $runtimeSummary = null;

    public ?array $runtimeLatest = null;

    public $trainingState = 'ready';
    public $trainingProgress = 0;
    public $trainingEpoch = 0;
    public $trainingLoss = 0.0;
    public $valLoss = 0.0;
    public $precision = 0.0;
    public $recall = 0.0;
    public $mAP50 = 0.0;
    public $trainingLog = [];

    public function mount()
    {
        $ml = app(MlClient::class);

        $this->mlOnline = $ml->healthy();
        $this->modelInfo = $ml->modelInfo();
        // Same-origin proxy: the browser must never resolve the ML service's
        // internal address itself (see routes/web.php ml.camera.*).
        $this->previewUrl = route('ml.camera.preview');

        $setting = Setting::current();
        $this->confThreshold = (float) $setting->confidence_threshold;

        $activePath = optional($setting->activeRun())->model_path
            ?? $this->modelInfo['configured_path']
            ?? '';
        $this->activeModelPath = (string) $activePath;
        $this->activeModelExists = $this->activeModelPath !== ''
            && Storage::disk('local')->exists($this->activeModelPath);

        $this->lastInferenceAt = Detection::max('detected_at');
        $this->scanArtifacts();
        // First-paint runtime telemetry (subsequent refreshes come via poll).
        $this->refreshRuntimeOnly();
    }

    /**
     * Deterministic demo training session (visualization only).
     *
     * No backend process is spawned, no weights are written, and no model
     * is activated. Progress always stays within 0..100:
     * preparing 0..10, training 20..85, evaluating 85..95, completed 100.
     *
     * Progression is intentionally one step per call so the UI visibly
     * advances via polling: startTrainingDemo() only enters the preparing
     * state, and each advanceTrainingStep() call moves a single step.
     */
    public function startTrainingDemo(): void
    {
        $this->trainingState = 'preparing';
        $this->trainingProgress = 5;
        $this->trainingEpoch = 0;
        $this->trainingLoss = 0.85;
        $this->valLoss = 0.95;
        $this->precision = 0.62;
        $this->recall = 0.60;
        $this->mAP50 = 0.58;
        $this->trainingLog = [
            ['at' => now()->format('H:i:s'), 'msg' => 'Preparing Vision Sorting dataset...'],
            ['at' => now()->format('H:i:s'), 'msg' => 'Classes: GREEN, YELLOW, RED'],
            ['at' => now()->format('H:i:s'), 'msg' => 'Loading YOLO architecture...'],
        ];
    }

    public function resetTraining(): void
    {
        $this->trainingState = 'ready';
        $this->trainingProgress = 0;
        $this->trainingEpoch = 0;
        $this->trainingLoss = 0.0;
        $this->valLoss = 0.0;
        $this->precision = 0.0;
        $this->recall = 0.0;
        $this->mAP50 = 0.0;
        $this->trainingLog = [];
    }

    /**
     * Advance the demo session by exactly one logical step.
     *
     * Called by conditional Blade polling while the session is active
     * (preparing / training / evaluating). Terminal states (ready /
     * completed) are no-ops so polling can safely stop there.
     */
    public function advanceTrainingStep(): void
    {
        if ($this->trainingState === 'ready' || $this->trainingState === 'completed') {
            return;
        }

        if ($this->trainingState === 'preparing') {
            $this->trainingProgress = 10;
            $this->trainingState = 'training';
            $this->trainingLog[] = ['at' => now()->format('H:i:s'), 'msg' => 'Preparing dataset complete. Starting training...'];
            $this->trainingLog = array_slice($this->trainingLog, -100);
            $this->trainingProgress = max(0, min(100, $this->trainingProgress));

            return;
        }

        if ($this->trainingState === 'training') {
            if ($this->trainingEpoch < 50) {
                $this->trainingEpoch++;
                $ratio = $this->trainingEpoch / 50;
                $this->trainingProgress = max(20, min(85, 20 + (int) round($ratio * 65)));
                $this->trainingLoss = max(0.0, round(0.85 - ($ratio * 0.60), 3));
                $this->valLoss = max(0.0, round(0.95 - ($ratio * 0.58), 3));
                $this->precision = max(0.0, min(1.0, round(0.62 + ($ratio * 0.28), 3)));
                $this->recall = max(0.0, min(1.0, round(0.60 + ($ratio * 0.28), 3)));
                $this->mAP50 = max(0.0, min(1.0, round(0.58 + ($ratio * 0.33), 3)));
                $this->trainingLog[] = ['at' => now()->format('H:i:s'), 'msg' => "Epoch {$this->trainingEpoch}/50 - loss={$this->trainingLoss} precision={$this->precision}"];
                $this->trainingLog = array_slice($this->trainingLog, -100);
            } else {
                $this->trainingState = 'evaluating';
                $this->trainingProgress = 85;
                $this->trainingLog[] = ['at' => now()->format('H:i:s'), 'msg' => 'Evaluating model...'];
                $this->trainingLog = array_slice($this->trainingLog, -100);
            }
            $this->trainingProgress = max(0, min(100, $this->trainingProgress));

            return;
        }

        if ($this->trainingState === 'evaluating') {
            if ($this->trainingProgress < 90) {
                $this->trainingProgress = 90;
                $this->precision = 0.905;
                $this->recall = 0.885;
                $this->mAP50 = 0.92;
                $this->trainingLog[] = ['at' => now()->format('H:i:s'), 'msg' => "Validation - precision={$this->precision} recall={$this->recall} mAP@50={$this->mAP50}"];
                $this->trainingLog = array_slice($this->trainingLog, -100);
            } elseif ($this->trainingProgress < 95) {
                $this->trainingProgress = 95;
                $this->precision = 0.91;
                $this->recall = 0.89;
                $this->mAP50 = 0.93;
                $this->trainingLog[] = ['at' => now()->format('H:i:s'), 'msg' => "Validation - precision={$this->precision} recall={$this->recall} mAP@50={$this->mAP50}"];
                $this->trainingLog = array_slice($this->trainingLog, -100);
            } else {
                $this->trainingState = 'completed';
                $this->trainingProgress = 100;
                $this->trainingLog[] = ['at' => now()->format('H:i:s'), 'msg' => '[Complete] Demo training session finished.'];
                $this->trainingLog = array_slice($this->trainingLog, -100);
            }
            $this->trainingProgress = max(0, min(100, $this->trainingProgress));

            return;
        }

        $this->resetTraining();
    }

    public function startPreview(): void
    {
        $this->previewing = true;
        $this->refreshPreview();
    }

    public function stopPreview(): void
    {
        $this->previewing = false;
    }

    /**
     * Slow poll target: fetch the latest preview snapshot and append new
     * frames to the session log. No-op unless previewing; writes nothing to
     * the database and issues no robot commands.
     *
     * Also refreshes the read-only live runtime telemetry (model/result
     * panels) from the shared ML runtime cache.
     */
    /**
     * Refresh the read-only live runtime telemetry only (no preview logic).
     */
    public function refreshRuntimeOnly(): void
    {
        $ml = app(MlClient::class);

        $this->runtimeSummary = Cache::remember('modeleval.runtime.summary', now()->addSeconds(5),
            fn () => $ml->statsSummary());
        $this->runtimeLatest = Cache::remember('modeleval.runtime.latest', now()->addSeconds(3),
            fn () => $ml->detectionsLatest());
    }

    public function refreshPreview(): void
    {
        $this->refreshRuntimeOnly();

        if (! $this->previewing) {
            return;
        }

        $snap = Cache::remember('model_eval.preview', now()->addSeconds(2), function () {
            return app(MlClient::class)->previewLatest();
        });

        if (! is_array($snap) || empty($snap['ok'])) {
            return;
        }

        $this->preview = $snap;

        $last = end($this->previewLog);
        if ($snap['at'] !== ($last['at'] ?? null)) {
            $this->previewLog[] = [
                'at' => $snap['at'],
                'class' => $snap['detected_class'],
                'confidence' => $snap['confidence'],
                'latency_ms' => $snap['latency_ms'],
                'pick_zone' => $snap['in_pick_zone'],
                'bbox' => $snap['bbox'],
                'center_x' => $snap['center_x'],
                'center_y' => $snap['center_y'],
            ];
            $this->previewLog = array_slice($this->previewLog, -15);
        }
    }

    /**
     * Real Ultralytics artifacts colocated with the active model, if any.
     * Never fabricates training curves: missing files are reported as such.
     */
    protected function scanArtifacts(): void
    {
        $base = $this->activeModelPath !== '' ? dirname($this->activeModelPath) : 'models/run-100';

        $expected = [
            'results.csv', 'results.png', 'confusion_matrix.png',
            'BoxF1_curve.png', 'BoxPR_curve.png', 'BoxP_curve.png', 'BoxR_curve.png',
        ];

        $disk = Storage::disk('local');
        $this->artifactChecklist = [];
        foreach ($expected as $name) {
            $this->artifactChecklist[] = [
                'name' => $name,
                'found' => $disk->exists($base.'/'.$name) || $disk->exists($base.'/artifacts/'.$name),
            ];
        }

        $this->artifactFiles = [];
        foreach ([$base, $base.'/artifacts'] as $dir) {
            if (! $disk->exists($dir)) {
                continue;
            }
            foreach ($disk->files($dir) as $path) {
                $this->artifactFiles[] = [
                    'name' => basename($path),
                    'dir' => $dir,
                    'size' => $disk->size($path),
                    'image' => (bool) preg_match('/\.(png|jpe?g)$/i', $path),
                ];
            }
        }
    }

    public function render()
    {
        // --- Real runtime data for graphs (detections table) ---
        $colored = Detection::query()->whereNotNull('color');

        $distribution = (clone $colored)
            ->selectRaw('color, count(*) as total')
            ->groupBy('color')
            ->pluck('total', 'color')
            ->toArray();

        $avgConfidence = (clone $colored)
            ->selectRaw('color, avg(confidence) as avg_conf')
            ->groupBy('color')
            ->pluck('avg_conf', 'color')
            ->toArray();

        // Per-hour detections today, aggregated in SQL (one tiny grouped
        // query — never fetch every row into PHP).
        $hourExpr = DB::getDriverName() === 'pgsql'
            ? 'EXTRACT(HOUR FROM detected_at)::int'
            : "CAST(strftime('%H', detected_at) AS INTEGER)";
        $hourly = array_fill(0, 24, 0);
        foreach (
            Detection::query()
                ->where('detected_at', '>=', now()->startOfDay())
                ->selectRaw("{$hourExpr} as h, COUNT(*) as total")
                ->groupBy('h')
                ->pluck('total', 'h') as $h => $total
        ) {
            $hourly[(int) $h] = (int) $total;
        }

        // Recent real detections with color context for the prediction log.
        $recentDetections = Detection::query()
            ->whereNotNull('color')
            ->latest('detected_at')
            ->limit(8)
            ->get();

        return view('livewire.model-evaluation.index', [
            'distribution' => $distribution,
            'avgConfidence' => $avgConfidence,
            'hourly' => $hourly,
            'recentDetections' => $recentDetections,
            'trainingState' => $this->trainingState,
            'trainingProgress' => $this->trainingProgress,
            'trainingEpoch' => $this->trainingEpoch,
            'trainingLoss' => $this->trainingLoss,
            'valLoss' => $this->valLoss,
            'precision' => $this->precision,
            'recall' => $this->recall,
            'mAP50' => $this->mAP50,
            'trainingLog' => $this->trainingLog,
        ]);
    }
}
