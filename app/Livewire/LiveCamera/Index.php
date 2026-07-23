<?php

namespace App\Livewire\LiveCamera;

use App\Models\Detection;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Services\MlClient;
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
            $this->addError('frame', 'ML service tidak merespons. Pastikan service berjalan di port 8001.');

            return;
        }

        $status = $result['status'] ?? 'recheck';

        // Auto-reject setting: flag defects distinctly in the log.
        $isDefect = in_array($status, Detection::FAILED_STATUSES, true);

        $detection = Detection::create([
            'code' => 'SCN-'.strtoupper(Str::random(6)),
            'product_id' => Product::inRandomOrder()->value('id'),
            'camera' => $this->camera,
            'conveyor' => $this->conveyor,
            'status' => $status,
            'qr_value' => $result['qr_value'] ?? null,
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

        // Source mode: 'webcam' (browser getUserMedia) or 'icam' (ICAM-300 RTSP
        // relayed as MJPEG by the ml-service).
        $cameraSource = Setting::current()->camera_source ?? 'webcam';

        return view('livewire.live-camera.index', [
            'stats' => $stats,
            'feed' => $feed,
            'mlOnline' => $mlOnline,
            'cameraSource' => $cameraSource,
            'streamUrl' => config('services.ml.stream_url'),
        ]);
    }
}
