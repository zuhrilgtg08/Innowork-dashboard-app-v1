<?php

namespace App\Livewire\LiveCamera;

use App\Models\Camera;
use App\Models\Detection;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Services\MlClient;
use App\Services\QcWorkflow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app', ['title' => 'Live Camera'])]
class Index extends Component
{
    use WithFileUploads;

    /** Captured JPEG frame uploaded from the browser canvas. */
    public $frame;

    /** Result of the last inference, for the on-screen verdict. */
    public ?array $lastResult = null;

    public string $camera = 'CAM-01';

    public string $conveyor = 'LINE-A';

    /** Live YOLO runtime telemetry (refreshed by poll, cached). */
    public ?array $runtime = null;

    public ?array $runtimeCamera = null;

    public ?array $runtimeSummary = null;

    public array $runtimeDetections = [];

    public ?string $runtimeFrameAt = null;

    public ?array $runtimeFrame = null;

    public ?string $runtimeFrameCamera = null;

    public array $runtimeConfidence = [];

    public array $runtimeTimeline = ['bucket_seconds' => 60, 'labels' => [], 'series' => []];

    /**
     * Refresh live YOLO runtime telemetry (polled from the view).
     *
     * All ML calls are cached and best-effort: when the service is offline
     * every prop degrades to null/empty and the view renders the clean
     * offline state instead of failing.
     */
    public function refreshRuntime(): void
    {
        $ml = app(MlClient::class);

        $this->runtime = Cache::remember('live.runtime.health', now()->addSeconds(5),
            fn () => $ml->runtimeHealth());
        $this->runtimeCamera = Cache::remember('live.runtime.camera', now()->addSeconds(5),
            fn () => $ml->cameraStatus());
        $this->runtimeSummary = Cache::remember('live.runtime.summary', now()->addSeconds(5),
            fn () => $ml->statsSummary());

        $latest = Cache::remember('live.runtime.latest', now()->addSeconds(3),
            fn () => $ml->detectionsLatest());
        $this->runtimeDetections = is_array($latest['detections'] ?? null)
            ? array_slice($latest['detections'], 0, 8)
            : [];
        $this->runtimeFrameAt = $latest['timestamp'] ?? null;
        $this->runtimeFrame = $latest['frame'] ?? null;
        $this->runtimeFrameCamera = $latest['camera'] ?? null;

        $confidence = Cache::remember('live.runtime.confidence', now()->addSeconds(5),
            fn () => $ml->confidenceStats(120));
        $this->runtimeConfidence = $confidence['samples'] ?? [];

        $this->runtimeTimeline = Cache::remember('live.runtime.timeline', now()->addSeconds(10),
            fn () => $ml->timelineStats(60, 60));
    }

    /**
     * Receive a captured frame, run it through the ML service, and persist a
     * Detection built from the model's verdict.
     */
    public function inferFrame(): void
    {
        $this->validate([
            'frame' => ['required', 'image', 'max:4096'],
        ]);

        $setting = Setting::current();
        $ml = app(MlClient::class);

        // Persist the frame on the public disk so it can be annotated/trained later.
        $framePath = $this->frame->store('frames', 'public');
        $absolute = Storage::disk('public')->path($framePath);

        $activeModel = optional($setting->activeRun())->model_path;

        $result = $ml->infer($absolute, $activeModel, (float) $setting->confidence_threshold, [
            'camera' => $this->camera,
            'conveyor' => $this->conveyor,
        ]);

        if (! $result) {
            // Keep the frame but tell the user the service is unreachable.
            $this->lastResult = ['status' => 'error', 'confidence' => 0];
            $this->addError('frame', 'The ML service is not responding. Make sure the service is running.');

            return;
        }

        $status = $result['status'] ?? 'recheck';
        $qrValue = $result['qr_value'] ?? null;

        // Auto-reject setting: flag defects distinctly in the log.
        $isDefect = in_array($status, Detection::FAILED_STATUSES, true);

        // Map to a product via the scanned QR; fall back to a random product so
        // the demo webcam (no real QR in view) still yields useful data.
        $productId = Product::resolveByQrValue($qrValue)?->id
            ?? Product::inRandomOrder()->value('id');

        $detection = Detection::create([
            'code' => 'SCN-'.strtoupper(Str::random(6)),
            'product_id' => $productId,
            'camera' => $this->camera,
            'conveyor' => $this->conveyor,
            'status' => $status,
            'qr_value' => $qrValue,
            'frame_path' => $framePath,
            'confidence' => $result['confidence'] ?? 0,
            'detected_at' => now(),
        ]);

        SystemLog::create([
            'level' => $isDefect && $setting->auto_reject_on_damage ? 'warning' : 'info',
            'source' => 'ai',
            'message' => "Live inference: {$detection->statusLabel()} ({$detection->confidence}%) on {$this->camera}.",
            'context' => ['detection_id' => $detection->id, 'boxes' => $result['boxes'] ?? []],
            'logged_at' => now(),
        ]);

        // Auto-reject workflow: divert defect to a return batch + command the arm.
        app(QcWorkflow::class)->handleFrame([$detection]);

        $this->lastResult = [
            'status' => $status,
            'label' => $detection->statusLabel(),
            'color' => $detection->statusColor(),
            'confidence' => $detection->confidence,
            'rejected' => $isDefect && $setting->auto_reject_on_damage,
        ];

        $this->reset('frame');
    }

    public function render()
    {
        // Single webcam that syncs with the dashboard — one aggregate card plus
        // the live detection feed (no multi-camera grid).
        $today = Detection::query()->where('detected_at', '>=', now()->startOfDay());

        $stats = [
            'total' => (clone $today)->count(),
            'passed' => (clone $today)->where('status', 'passed')->count(),
            'failed' => (clone $today)->whereIn('status', Detection::FAILED_STATUSES)->count(),
            'last_seen' => (clone $today)->max('detected_at'),
        ];

        $feed = Detection::query()
            ->with('product')
            ->latest('detected_at')
            ->limit(15)
            ->get();

        // Cache the health probe so wire:poll doesn't hammer the ML service.
        $mlOnline = Cache::remember('ml.health', now()->addSeconds(10), fn () => app(MlClient::class)->healthy());

        // Live YOLO runtime telemetry for the preview panels (cached,
        // best-effort; null/empty when the ML service is offline).
        $this->refreshRuntime();

        // Source mode: 'webcam' (browser getUserMedia) or 'icam' (ICAM-300 RTSP
        // relayed as MJPEG through the same-origin Laravel proxy).
        $settingSource = Setting::current()->camera_source ?? 'webcam';
        $visionMode = (bool) config('services.sorting.competition_mode', false);

        // In Vision Sorting mode the production source is the Advantech
        // iCAM-300 server stream: the page must never depend on the viewer's
        // laptop webcam, which IoT Suite iframes typically block. An explicit
        // ?source=webcam override keeps the legacy webcam reachable for
        // development without changing the stored setting.
        $explicitWebcam = request()->query('source') === 'webcam';
        $cameraSource = ($visionMode && $settingSource === 'webcam' && ! $explicitWebcam)
            ? 'icam'
            : $settingSource;

        // Camera fleet overview: each configured camera with today's throughput.
        $startOfDay = now()->startOfDay();
        $fleet = Camera::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->get()
            ->map(function (Camera $cam) use ($startOfDay) {
                $today = Detection::where('camera', $cam->name)->where('detected_at', '>=', $startOfDay);

                return [
                    'name' => $cam->name,
                    'mode' => $cam->mode,
                    'fps' => number_format($cam->fps, 1),
                    'last_seen' => (clone $today)->max('detected_at'),
                    'detections' => (clone $today)->count(),
                ];
            });

        return view('livewire.live-camera.index', [
            'stats' => $stats,
            'feed' => $feed,
            'mlOnline' => $mlOnline,
            'cameraSource' => $cameraSource,
            // Same-origin proxy (routes/web.php): never expose the ML
            // service's internal address to the browser.
            'streamUrl' => route('ml.camera.stream'),
            'previewUrl' => route('ml.camera.preview'),
            'fleet' => $fleet,
            'visionMode' => $visionMode,
        ]);
    }
}
