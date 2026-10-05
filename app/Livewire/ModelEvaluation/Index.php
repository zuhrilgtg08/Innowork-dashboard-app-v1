<?php

namespace App\Livewire\ModelEvaluation;

use App\Models\Detection;
use App\Models\Setting;
use App\Services\MlClient;
use Illuminate\Support\Facades\Cache;
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

    public function mount()
    {
        $ml = app(MlClient::class);

        $this->mlOnline = $ml->healthy();
        $this->modelInfo = $ml->modelInfo();
        $this->previewUrl = (string) config('services.ml.preview_url');

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
     */
    public function refreshPreview(): void
    {
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

        // Per-hour detections today, bucketed in PHP (database-agnostic).
        $todayRows = Detection::query()
            ->where('detected_at', '>=', now()->startOfDay())
            ->select(['color', 'detected_at'])
            ->get();
        $hourly = array_fill(0, 24, 0);
        foreach ($todayRows as $row) {
            $hourly[(int) $row->detected_at->format('G')]++;
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
        ]);
    }
}
