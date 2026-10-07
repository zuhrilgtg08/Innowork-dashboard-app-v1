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

    /** ESP32 robot target (TCP). Defaults match the ML service fallback. */
    public string $esp32Ip = '192.168.100.77';

    public int $esp32Port = 5000;

    /** Result of the last manual send to the ESP32 (payload + OK/GAGAL). */
    public ?array $lastSend = null;

    /** Send history, newest first (max 10), for delivery monitoring. */
    public array $sendHistory = [];

    /**
     * First paint: load runtime telemetry once so the view has data without
     * waiting for the first poll tick. Subsequent updates come only from the
     * explicit wire:poll target — render() stays cheap (DB only) so menu
     * navigation never blocks on ML HTTP calls.
     */
    public function mount(): void
    {
        $this->refreshRuntime();
    }

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
     * Kirim deteksi terbaru (yang tampil di preview + Current Detection)
     * ke ESP32 robot via ML service. Best-effort: hasilnya tampil di UI.
     */
    public function sendToEsp32(): void
    {
        $this->validate([
            'esp32Ip' => ['required', 'string', 'max:255'],
            'esp32Port' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        $result = app(MlClient::class)->sendRobotLatest($this->esp32Ip, $this->esp32Port);

        $this->lastSend = $result ?? ['ok' => false, 'error' => 'ML service offline'];

        array_unshift($this->sendHistory, [
            'at' => now()->format('H:i:s'),
            'esp' => $this->lastSend['esp'] ?? $this->esp32Ip.':'.$this->esp32Port,
            'payload' => $this->lastSend['payload'] ?? null,
            'ok' => (bool) ($this->lastSend['ok'] ?? false),
            'error' => $this->lastSend['error'] ?? null,
        ]);
        $this->sendHistory = array_slice($this->sendHistory, 0, 10);
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
        $startOfDay = now()->startOfDay();

        // One aggregate query (total/passed/failed/last_seen) instead of four
        // round-trips on every poll/render, so the page stays light.
        $failedIn = implode(',', array_map(
            fn ($s) => "'".str_replace("'", "''", $s)."'",
            Detection::FAILED_STATUSES
        ));
        $statsRow = Detection::query()
            ->where('detected_at', '>=', $startOfDay)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'passed' THEN 1 ELSE 0 END) as passed")
            ->selectRaw("SUM(CASE WHEN status IN ({$failedIn}) THEN 1 ELSE 0 END) as failed")
            ->selectRaw('MAX(detected_at) as last_seen')
            ->first();

        $stats = [
            'total' => (int) ($statsRow->total ?? 0),
            'passed' => (int) ($statsRow->passed ?? 0),
            'failed' => (int) ($statsRow->failed ?? 0),
            'last_seen' => $statsRow->last_seen ?? null,
        ];

        $feed = Detection::query()
            ->with('product')
            ->latest('detected_at')
            ->limit(15)
            ->get();

        // Cache the health probe so wire:poll doesn't hammer the ML service.
        $mlOnline = Cache::remember('ml.health', now()->addSeconds(10), fn () => app(MlClient::class)->healthy());

        // Live YOLO runtime telemetry is refreshed by the explicit poll target
        // (refreshRuntime) and seeded in mount() — never here. Calling it from
        // render() would re-fire several ML HTTP calls on every poll/render and
        // stall menu navigation.

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
        // Counts are aggregated in ONE grouped query (not 2 per camera) so the
        // fleet grid costs the same with 2 or 20 cameras.
        $cams = Camera::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        $fleetAgg = collect();
        if ($cams->isNotEmpty()) {
            $fleetAgg = Detection::query()
                ->where('detected_at', '>=', $startOfDay)
                ->whereIn('camera', $cams->pluck('name')->all())
                ->selectRaw('camera, COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status IN ({$failedIn}) THEN 1 ELSE 0 END) as failed")
                ->selectRaw('MAX(detected_at) as last_seen')
                ->groupBy('camera')
                ->get()
                ->keyBy('camera');
        }

        $fleet = $cams->map(function (Camera $cam) use ($fleetAgg) {
            $row = $fleetAgg->get($cam->name);

            return [
                'name' => $cam->name,
                'conveyor' => $cam->conveyor,
                'live' => $cam->isLive(),
                'detections' => (int) ($row->total ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'last_seen' => $row->last_seen ?? null,
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
