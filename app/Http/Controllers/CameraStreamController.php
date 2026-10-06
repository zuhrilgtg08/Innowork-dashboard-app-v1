<?php

namespace App\Http\Controllers;

use App\Services\MlClient;
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
 *   GET /ml/camera/preview  MJPEG relay of the annotated YOLO preview
 *   GET /ml/camera/frame    Latest frame as a single JPEG (pollable)
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
