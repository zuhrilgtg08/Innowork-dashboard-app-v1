<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Detection;
use App\Models\Setting;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ingest endpoint for the ICAM-300 stream pipeline. The FastAPI ml-service
 * pulls the camera's RTSP feed, runs YOLO inference, and POSTs each verdict
 * here (HMAC-signed, see verify.ml middleware). We persist it as a Detection —
 * the same shape the manual webcam path in LiveCamera\Index produces.
 */
class CameraController extends Controller
{
    public function ingest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'confidence' => ['nullable', 'numeric'],
            'boxes' => ['nullable', 'array'],
            'camera' => ['nullable', 'string', 'max:50'],
            'conveyor' => ['nullable', 'string', 'max:50'],
            'qr_value' => ['nullable', 'string', 'max:255'],
            'frame_jpeg_b64' => ['nullable', 'string'],
        ]);

        // Normalise the status against the known QC set; unknown => recheck.
        $status = array_key_exists($data['status'], Detection::STATUSES)
            ? $data['status']
            : 'recheck';

        // Persist the captured frame on the public disk (annotatable later).
        $framePath = null;
        if (! empty($data['frame_jpeg_b64'])) {
            $binary = base64_decode($data['frame_jpeg_b64'], true);
            if ($binary !== false) {
                $framePath = 'frames/icam-'.now()->format('Ymd_His').'-'.Str::random(6).'.jpg';
                Storage::disk('public')->put($framePath, $binary);
            }
        }

        $setting = Setting::current();
        $isDefect = in_array($status, Detection::FAILED_STATUSES, true);

        $detection = Detection::create([
            'code' => 'SCN-'.strtoupper(Str::random(6)),
            'product_id' => null, // QR-to-product mapping is a later refinement.
            'camera' => $data['camera'] ?? 'ICAM-300',
            'conveyor' => $data['conveyor'] ?? 'LINE-A',
            'status' => $status,
            'qr_value' => $data['qr_value'] ?? null,
            'frame_path' => $framePath,
            'confidence' => $data['confidence'] ?? 0,
            'detected_at' => now(),
        ]);

        SystemLog::create([
            'level' => $isDefect && $setting->auto_reject_on_damage ? 'warning' : 'info',
            'source' => 'camera',
            'message' => "ICAM inference: {$detection->statusLabel()} ({$detection->confidence}%) on {$detection->camera}.",
            'context' => ['detection_id' => $detection->id, 'boxes' => $data['boxes'] ?? []],
            'logged_at' => now(),
        ]);

        return response()->json(['ok' => true, 'detection_id' => $detection->id]);
    }
}
