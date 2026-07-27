<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Detection;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Services\QcWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
    public function ingest(Request $request, QcWorkflow $qc): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'confidence' => ['nullable', 'numeric'],
            'boxes' => ['nullable', 'array'],
            'detections' => ['nullable', 'array'],
            'detections.*.status' => ['nullable', 'string'],
            'detections.*.confidence' => ['nullable', 'numeric'],
            'camera' => ['nullable', 'string', 'max:50'],
            'conveyor' => ['nullable', 'string', 'max:50'],
            'qr_value' => ['nullable', 'string', 'max:255'],
            'frame_jpeg_b64' => ['nullable', 'string'],
        ]);

        $camera = $data['camera'] ?? 'ICAM-300';
        $conveyor = $data['conveyor'] ?? 'LINE-A';
        $qrValue = $data['qr_value'] ?? null;

        // Frame dedup: a slow conveyor / a camera re-sending the same still can
        // POST identical frames back-to-back. Skip if we've seen this exact frame
        // on this camera within the dedup window to avoid double-counting.
        if (! empty($data['frame_jpeg_b64'])) {
            $hash = hash('sha256', $camera.'|'.$data['frame_jpeg_b64']);
            $key = 'camera:frame:'.$hash;
            if (Cache::has($key)) {
                return response()->json(['ok' => true, 'deduped' => true, 'count' => 0]);
            }
            Cache::put($key, true, now()->addSeconds(10));
        }

        // Persist the captured frame once; all detections from this frame share it.
        $framePath = null;
        if (! empty($data['frame_jpeg_b64'])) {
            $binary = base64_decode($data['frame_jpeg_b64'], true);
            if ($binary !== false) {
                $framePath = 'frames/icam-'.now()->format('Ymd_His').'-'.Str::random(6).'.jpg';
                Storage::disk('public')->put($framePath, $binary);
            }
        }

        // Resolve the product once from the frame's QR (all boxes are the same
        // scanned item on the conveyor).
        $productId = Product::resolveByQrValue($qrValue)?->id;

        // A frame may carry many detected boxes. Fall back to the single
        // top-level verdict when the ml-service sends no per-box list.
        $items = ! empty($data['detections'])
            ? $data['detections']
            : [['status' => $data['status'], 'confidence' => $data['confidence'] ?? 0]];

        $created = [];
        foreach ($items as $item) {
            $status = array_key_exists($item['status'] ?? '', Detection::STATUSES)
                ? $item['status']
                : 'recheck';

            $created[] = Detection::create([
                'code' => 'SCN-'.strtoupper(Str::random(6)),
                'product_id' => $productId,
                'camera' => $camera,
                'conveyor' => $conveyor,
                'status' => $status,
                'qr_value' => $qrValue,
                'frame_path' => $framePath,
                'confidence' => $item['confidence'] ?? 0,
                'detected_at' => now(),
            ]);
        }

        // Auto-reject workflow: group defects into a return batch + command arm.
        $batch = $qc->handleFrame($created);

        // One summary log per frame (not per box).
        $setting = Setting::current();
        $defects = collect($created)->filter(
            fn (Detection $d) => in_array($d->status, Detection::FAILED_STATUSES, true)
        );
        $count = count($created);

        SystemLog::create([
            'level' => $defects->isNotEmpty() && $setting->auto_reject_on_damage ? 'warning' : 'info',
            'source' => 'camera',
            'message' => "ICAM frame: {$count} detection(s), {$defects->count()} defect(s) on {$camera}.",
            'context' => [
                'detection_ids' => collect($created)->pluck('id')->all(),
                'qr_value' => $qrValue,
                'product_id' => $productId,
                'boxes' => $data['boxes'] ?? [],
            ],
            'logged_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'count' => $count,
            'detection_ids' => collect($created)->pluck('id')->all(),
            'product_id' => $productId,
            'return_batch_id' => $batch?->id,
        ]);
    }
}
