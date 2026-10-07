<?php

namespace App\Http\Controllers;

use App\Services\MlClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Same-origin relay for the Advantech iCAM-300 camera streams.
 *
 * The FastAPI ML service listens on an internal address (usually
 * 127.0.0.1:8001) that a remote browser — or a browser embedded in the
 * Advantech IoT Suite iframe — cannot reach directly (127.0.0.1 would resolve
 * to the viewer's own machine). These endpoints keep the browser on the
 * Laravel origin and proxy to the ML service server-side, degrading to a
 * clean 503 when the service is offline instead of leaking the internal URL.
 *
 * Browser-facing URLs:
 *   GET /ml/camera/stream   MJPEG relay of the live camera feed
 *   GET /ml/camera/raw      MJPEG relay of the raw camera feed (no overlay)
 *   GET /ml/camera/preview  MJPEG relay of the annotated YOLO preview
 *   GET /ml/camera/frame    Latest raw frame as a single JPEG (pollable)
 *   GET /ml/camera/preview/frame  Latest annotated frame as a single JPEG
 *   GET /ml/detections/latest     Latest YOLO result (JSON)
 *   GET /ml/stats/summary         Runtime counters (JSON)
 *   GET /ml/stats/confidence      Recent confidence samples (JSON)
 *   GET /ml/stats/timeline        Detections per time bucket (JSON)
 */
class CameraStreamController extends Controller
{
    /**
     * MJPEG relay of the live iCAM-300 feed.
     */
    public function stream(): StreamedResponse|Response
    {
        return $this->relay((string) config('services.ml.stream_url'));
    }

    /**
     * MJPEG relay of the raw iCAM-300 feed (no YOLO overlay).
     */
    public function raw(): StreamedResponse|Response
    {
        return $this->relay((string) config('services.ml.raw_url'));
    }

    /**
     * MJPEG relay of the annotated (bounding-box) preview feed.
     */
    public function preview(): StreamedResponse|Response
    {
        return $this->relay((string) config('services.ml.preview_url'));
    }

    /**
     * The latest camera frame as a single JPEG.
     *
     * Clients that cannot hold an MJPEG connection (or single-threaded dev
     * servers) poll this instead. Responses are explicitly uncacheable.
     */
    public function frame(MlClient $ml): Response
    {
        $jpeg = $ml->cameraFrame();

        if ($jpeg === null) {
            return response('Camera unavailable', 503, [
                'Cache-Control' => 'no-store',
            ]);
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * The latest annotated (YOLO overlay) frame as a single JPEG.
     */
    public function previewFrame(MlClient $ml): Response
    {
        $jpeg = $ml->previewFrame();

        if ($jpeg === null) {
            return response('Preview unavailable', 503, [
                'Cache-Control' => 'no-store',
            ]);
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Latest YOLO result as JSON (proxied, graceful 503 when offline).
     */
    public function detectionsLatest(MlClient $ml): JsonResponse
    {
        $data = $ml->detectionsLatest();

        if ($data === null) {
            return response()->json(['ok' => false, 'error' => 'ML service offline'], 503);
        }

        return response()->json($data);
    }

    /**
     * Runtime counters as JSON (proxied, graceful 503 when offline).
     */
    public function statsSummary(MlClient $ml): JsonResponse
    {
        $data = $ml->statsSummary();

        if ($data === null) {
            return response()->json(['error' => 'ML service offline'], 503);
        }

        return response()->json($data);
    }

    /**
     * Recent confidence samples as JSON (empty samples when offline —
     * graphs render an honest empty state instead of failing).
     */
    public function confidenceStats(MlClient $ml): JsonResponse
    {
        return response()->json($ml->confidenceStats());
    }

    /**
     * Detections per time bucket as JSON (empty series when offline).
     */
    public function timelineStats(MlClient $ml): JsonResponse
    {
        return response()->json($ml->timelineStats());
    }

    /**
     * Kirim deteksi terbaru ke ESP32 (pixel x,y + G/R/Y via ML service).
     */
    public function sendRobot(Request $request, MlClient $ml): JsonResponse
    {
        $data = $request->validate([
            'esp_host' => ['nullable', 'string', 'max:255'],
            'esp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        $result = $ml->sendRobotLatest($data['esp_host'] ?? null, $data['esp_port'] ?? null);

        if ($result === null) {
            return response()->json(['ok' => false, 'error' => 'ML service offline'], 503);
        }

        return response()->json($result);
    }

    /**
     * Stream an upstream MJPEG URL through to the browser.
     */
    protected function relay(string $upstreamUrl): StreamedResponse|Response
    {
        if ($upstreamUrl === '') {
            abort(503, 'Camera stream is not configured.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $upstream = @fopen($upstreamUrl, 'rb', false, $context);

        if ($upstream === false) {
            // ML service offline: let the UI render its clean offline state.
            abort(503, 'Camera stream unavailable.');
        }

        // Best-effort: surface the upstream content type, defaulting to MJPEG.
        $contentType = 'multipart/x-mixed-replace; boundary=frame';
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, strlen('Content-Type:')));
                break;
            }
        }

        // Never cache a live stream, and drop PHP limits for the relay.
        set_time_limit(0);

        return response()->stream(function () use ($upstream) {
            try {
                while (! feof($upstream)) {
                    echo fread($upstream, 8192);
                    if (connection_aborted()) {
                        break;
                    }
                    flush();
                }
            } finally {
                fclose($upstream);
            }
        }, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
