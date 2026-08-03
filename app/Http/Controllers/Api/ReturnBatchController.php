<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesJson;
use App\Http\Controllers\Controller;
use App\Models\Detection;
use App\Models\ReturnBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * QC return batches for the mobile app — REST counterpart of
 * `App\Livewire\Returns\Index`.
 *
 * Batches are created by the auto-reject workflow
 * (`App\Services\QcWorkflow`), never by a client, so there is no store()
 * here — only listing, inspecting and resolving.
 */
class ReturnBatchController extends Controller
{
    use PaginatesJson;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_keys(ReturnBatch::STATUSES))],
            'conveyor' => ['nullable', 'string', 'max:255'],
            'per_page' => $this->perPageRules(),
        ]);

        $batches = ReturnBatch::query()
            ->withCount('detections')
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['conveyor'] ?? null, fn ($q, $conveyor) => $q->where('conveyor', $conveyor))
            ->latest()
            ->paginate($this->perPage($validated));

        return $this->paginated($batches, fn (ReturnBatch $batch) => $this->payload($batch));
    }

    /**
     * A single batch with the detections it collected.
     */
    public function show(ReturnBatch $returnBatch): JsonResponse
    {
        $returnBatch->loadCount('detections')->load(['detections.product', 'resolver']);

        return response()->json([
            'data' => $this->payload($returnBatch) + [
                'detections' => $returnBatch->detections->map(fn (Detection $d) => [
                    'id' => $d->id,
                    'code' => $d->code,
                    'status' => $d->status,
                    'status_label' => Detection::STATUSES[$d->status]['label'] ?? $d->status,
                    'camera' => $d->camera,
                    'conveyor' => $d->conveyor,
                    'confidence' => $d->confidence,
                    'detected_at' => optional($d->detected_at)->toIso8601String(),
                    'product' => $d->product
                        ? ['id' => $d->product->id, 'code' => $d->product->code, 'name' => $d->product->name]
                        : null,
                ])->values()->all(),
            ],
        ]);
    }

    /**
     * Mark a batch as resolved, attributing it to the calling user.
     */
    public function resolve(Request $request, ReturnBatch $returnBatch): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($returnBatch->status === 'resolved') {
            return response()->json([
                'message' => 'Return batch ini sudah ditandai selesai.',
            ], 409);
        }

        if (! empty($data['notes'])) {
            $returnBatch->notes = $data['notes'];
        }

        $returnBatch->resolve($request->user()->id);

        return response()->json([
            'message' => "Return batch #{$returnBatch->id} ditandai selesai.",
            'data' => $this->payload($returnBatch->fresh()->loadCount('detections')->load('resolver')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ReturnBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'conveyor' => $batch->conveyor,
            'reason' => $batch->reason,
            'status' => $batch->status,
            'status_label' => $batch->statusLabel(),
            'status_color' => $batch->statusColor(),
            'notes' => $batch->notes,
            'detections_count' => $batch->detections_count ?? null,
            'resolved_by' => $batch->relationLoaded('resolver') && $batch->resolver
                ? ['id' => $batch->resolver->id, 'name' => $batch->resolver->name]
                : null,
            'resolved_at' => optional($batch->resolved_at)->toIso8601String(),
            'created_at' => optional($batch->created_at)->toIso8601String(),
        ];
    }
}
