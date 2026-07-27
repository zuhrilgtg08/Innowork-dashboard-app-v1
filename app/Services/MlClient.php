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
}
