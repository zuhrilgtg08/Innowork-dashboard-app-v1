<?php

namespace App\Services;

use App\Models\TrainingRun;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP wrapper around the FastAPI ML service. All calls are best-effort:
 * transport failures are caught so Livewire screens can degrade gracefully
 * (e.g. show "ML service offline") instead of throwing 500s.
 */
class MlClient
{
    protected function client(int $timeout = 10): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.ml.url'), '/'))
            ->timeout($timeout)
            ->acceptJson();
    }

    /**
     * Is the ML service reachable and responsive?
     */
    public function healthy(): bool
    {
        try {
            return $this->client(3)->get('/health')->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Kick off a training run. Returns true if the service accepted the job.
     * The service reports progress asynchronously via signed callbacks.
     *
     * @param  array<int, array{image_path: string, label: string, bbox: ?array, split: string}>  $annotations
     */
    public function startTraining(TrainingRun $run, array $annotations): bool
    {
        try {
            $response = $this->client(30)->post('/train', [
                'run_id' => $run->id,
                'epochs' => $run->epochs,
                'imgsz' => 320,
                'storage_path' => storage_path('app'),
                'callback_url' => url('/api/ml/training/'.$run->id),
                'annotations' => $annotations,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('ML startTraining failed', ['run' => $run->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Ask the ML service to hot-reload model weights (drop its cached YOLO
     * handles) so a newly activated model takes effect without a restart.
     * Best-effort: returns true if the service acknowledged.
     */
    public function reloadModel(?string $modelPath = null): bool
    {
        try {
            return $this->client(10)
                ->asJson()
                ->post('/reload-model', array_filter(['model_path' => $modelPath]))
                ->successful();
        } catch (\Throwable $e) {
            Log::warning('ML reloadModel failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Run inference on a single captured frame.
     *
     * @param  array<string, mixed>  $ctx  camera/conveyor/product context
     * @return array{status: string, confidence: float, boxes: array}|null
     */
    public function infer(string $imageAbsolutePath, ?string $modelPath, float $conf, array $ctx = []): ?array
    {
        try {
            $response = $this->client(60)
                ->attach('frame', file_get_contents($imageAbsolutePath), 'frame.jpg')
                ->post('/infer', array_filter([
                    'model_path' => $modelPath,
                    'conf' => $conf,
                    'camera' => $ctx['camera'] ?? null,
                    'conveyor' => $ctx['conveyor'] ?? null,
                    'product_id' => $ctx['product_id'] ?? null,
                ], fn ($v) => $v !== null));

            if (! $response->successful()) {
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('ML infer failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Real metadata about the active YOLO weights (path, size, classes,
     * inference device) from GET /model/info. No inference is run.
     *
     * @return array{path: string, file_size_bytes: ?int, classes: array, class_count: int, inference_device: string}|null
     */
    public function modelInfo(): ?array
    {
        try {
            $response = $this->client(10)->get('/model/info');

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            Log::warning('ML modelInfo failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Latest Model Preview inference snapshot from GET /preview/latest.
     * Read-only visualization data: never triggers arm commands.
     */
    public function previewLatest(): ?array
    {
        try {
            $response = $this->client(5)->get('/preview/latest');

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Full runtime health from GET /health: model/camera state plus
     * inference telemetry (fps, latency). Null when the service is offline.
     *
     * @return array{status: string, model_loaded: bool, camera_connected: bool, camera_mode: string, camera_fps: float, inference_fps: float, last_inference_ms: ?float}|null
     */
    public function runtimeHealth(): ?array
    {
        try {
            $response = $this->client(3)->get('/health');

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            Log::warning('ML runtimeHealth failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Latest continuous-inference result from GET /detections/latest.
     * Null when offline or when the model reports MODEL_ERROR.
     */
    public function detectionsLatest(): ?array
    {
        try {
            $response = $this->client(5)->get('/detections/latest');

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            Log::warning('ML detectionsLatest failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Runtime counters from GET /stats/summary (GREEN/YELLOW/RED counts,
     * totals, fps, latency, uptime). Null when offline.
     */
    public function statsSummary(): ?array
    {
        try {
            $response = $this->client(5)->get('/stats/summary');

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable $e) {
            Log::warning('ML statsSummary failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Recent confidence samples from GET /stats/confidence for graphing.
     * Returns ['samples' => [...], 'count' => n]; empty samples when offline.
     */
    public function confidenceStats(int $limit = 120): array
    {
        try {
            $response = $this->client(5)->get('/stats/confidence', ['limit' => $limit]);

            return $response->successful() ? (array) $response->json() : ['samples' => [], 'count' => 0];
        } catch (\Throwable $e) {
            Log::warning('ML confidenceStats failed', ['error' => $e->getMessage()]);

            return ['samples' => [], 'count' => 0];
        }
    }

    /**
     * Detections per time bucket from GET /stats/timeline.
     * Returns ['bucket_seconds' => n, 'labels' => [...], 'series' => [...]];
     * empty series when offline.
     */
    public function timelineStats(int $minutes = 60, int $bucketSeconds = 60): array
    {
        try {
            $response = $this->client(5)->get('/stats/timeline', [
                'minutes' => $minutes,
                'bucket_seconds' => $bucketSeconds,
            ]);

            return $response->successful()
                ? (array) $response->json()
                : ['bucket_seconds' => $bucketSeconds, 'labels' => [], 'series' => []];
        } catch (\Throwable $e) {
            Log::warning('ML timelineStats failed', ['error' => $e->getMessage()]);

            return ['bucket_seconds' => $bucketSeconds, 'labels' => [], 'series' => []];
        }
    }

    /**
     * The latest annotated (YOLO overlay) frame as JPEG bytes, or null when
     * the service is unreachable or no inference has run yet.
     */
    public function previewFrame(): ?string
    {
        try {
            $response = $this->client(5)->get('/camera/preview/frame');

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::warning('ML previewFrame failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Kirim deteksi terbaru (pixel x,y + G/R/Y) ke ESP32 via POST /robot/send.
     * Return ['ok','sent','esp','payload','detection',...] atau null saat offline.
     */
    public function sendRobotLatest(?string $host = null, ?int $port = null): ?array
    {
        try {
            $response = $this->client(10)->post('/robot/send', array_filter([
                'esp_host' => $host,
                'esp_port' => $port,
            ], fn ($v) => $v !== null));

            return $response->successful() ? (array) $response->json() : null;
        } catch (\Throwable $e) {
            Log::warning('ML sendRobotLatest failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Liveness/mode of the live camera source (ICAM-300 or simulator).
     *
     * @return array{connected: bool, mode: string, source: ?string, fps: float}|null
     */
    public function cameraStatus(): ?array
    {
        try {
            $response = $this->client(3)->get('/camera/status');

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            // The runtime reports LIVE/SIMULATOR/OFFLINE; existing consumers
            // compare lowercase, so normalize here and let views render labels.
            if (is_array($json) && isset($json['mode'])) {
                $json['mode'] = strtolower((string) $json['mode']);
            }

            return $json;
        } catch (\Throwable $e) {
            Log::warning('ML cameraStatus failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The latest camera frame as raw JPEG bytes, or null when the service is
     * unreachable or has no frame yet.
     *
     * Deliberately a single still rather than the MJPEG stream: native mobile
     * image loaders cannot render `multipart/x-mixed-replace`, so clients poll
     * this instead. Kept on a short timeout so a stalled camera cannot pin a
     * PHP worker for long.
     */
    public function cameraFrame(): ?string
    {
        try {
            $response = $this->client(5)->get('/camera/frame');

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::warning('ML cameraFrame failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
