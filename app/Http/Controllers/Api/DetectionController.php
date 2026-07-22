<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Detection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only list of the most recent QC detections for the mobile dashboard.
 * Simple pagination; optional filter by a known Detection status.
 */
class DetectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_keys(Detection::STATUSES))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $validated['per_page'] ?? 20;

        $detections = Detection::query()
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('detected_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $detections->getCollection()->map(fn (Detection $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'status' => $d->status,
                'status_label' => $d->statusLabel(),
                'camera' => $d->camera,
                'conveyor' => $d->conveyor,
                'confidence' => $d->confidence,
                'qr_value' => $d->qr_value,
                'detected_at' => optional($d->detected_at)->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $detections->currentPage(),
                'per_page' => $detections->perPage(),
                'total' => $detections->total(),
                'last_page' => $detections->lastPage(),
            ],
        ]);
    }
}
