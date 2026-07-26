<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesJson;
use App\Http\Controllers\Controller;
use App\Models\Camera;
use App\Services\MlClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Camera list + live feed for the mobile app.
 *
 * Separate from `App\Http\Controllers\Api\CameraController`, which is the
 * machine-facing ingest endpoint the ml-service POSTs verdicts to. This one is
 * read-only and user-facing.
 *
 * The live frame is proxied rather than handed to the client as a direct
 * ml-service URL, for two reasons: the ml-service has no authentication of its
 * own (it is meant to sit on an internal network), and it usually listens on
 * localhost where a phone cannot reach it. Proxying keeps the feed behind the
 * same Sanctum token as the rest of the API.
 */
class CameraFeedController extends Controller
{
    use PaginatesJson;

    /**
     * Cameras configured on the line.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'per_page' => $this->perPageRules(),
        ]);

        $cameras = Camera::query()
            ->when(
                array_key_exists('is_active', $validated) && $validated['is_active'] !== null,
                fn ($q) => $q->where('is_active', $validated['is_active'])
            )
            ->orderBy('position')
            ->paginate($this->perPage($validated));

        return $this->paginated($cameras, fn (Camera $camera) => [
            'id' => $camera->id,
            'name' => $camera->name,
            'conveyor' => $camera->conveyor,
            'is_active' => (bool) $camera->is_active,
            'position' => (int) $camera->position,
            // `rtsp_url` is intentionally omitted: it frequently embeds
            // credentials (rtsp://user:pass@host) and a client never needs it —
            // frames come through the proxy below.
            'is_live' => $camera->isLive(),
            'source_kind' => $camera->isLive() ? 'rtsp' : 'simulator',
        ]);
    }

    /**
     * Liveness of the active camera source, straight from the ml-service.
     *
     * Note the ml-service keeps a single capture thread, so this reflects the
     * one active source rather than any specific row from `index()`.
     */
    public function status(MlClient $ml): JsonResponse
    {
        $status = $ml->cameraStatus();

        if ($status === null) {
            // Degrade instead of 500ing: the screen should say "offline", not break.
            return response()->json([
                'data' => [
                    'connected' => false,
                    'mode' => 'offline',
                    'fps' => 0,
                    'service_reachable' => false,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'connected' => (bool) ($status['connected'] ?? false),
                'mode' => $status['mode'] ?? 'offline',
                'fps' => (float) ($status['fps'] ?? 0),
                'service_reachable' => true,
            ],
        ]);
    }

    /**
     * The latest camera frame as a JPEG.
     *
     * Clients poll this to build a live view. Responses are explicitly
     * uncacheable — a cached frame would freeze the feed.
     */
    public function frame(MlClient $ml): Response
    {
        $jpeg = $ml->cameraFrame();

        if ($jpeg === null) {
            return response('', 503, [
                'Cache-Control' => 'no-store',
            ]);
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
