<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesJson;
use App\Http\Controllers\Controller;
use App\Livewire\Annotation\Index as AnnotationScreen;
use App\Models\Annotation;
use App\Models\Detection;
use App\Services\AutoRetrain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Labelling queue for the mobile app — REST counterpart of
 * {@see AnnotationScreen}. Reuses the same storage rules (whole-frame label,
 * AI vs human source, opportunistic auto-retrain) so a label approved from a
 * phone ends up identical to one approved on the web dashboard.
 */
class AnnotationController extends Controller
{
    use PaginatesJson;

    /**
     * Detections still waiting for a label: not yet annotated, and either a
     * real captured frame or a QC failure/workflow state worth reviewing.
     * Mirrors AnnotationScreen::render()'s `$queue` query exactly.
     */
    public function queue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(array_keys(Detection::STATUSES))],
            'per_page' => $this->perPageRules(),
        ]);

        $annotatedIds = Annotation::whereNotNull('detection_id')->pluck('detection_id');

        $queue = Detection::query()
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->whereNotIn('id', $annotatedIds)
            ->where(fn ($q) => $q->whereNotNull('frame_path')
                ->orWhereIn('status', array_merge(Detection::FAILED_STATUSES, ['recheck', 'returned'])))
            ->with('product')
            ->orderByRaw('frame_path IS NULL')
            ->latest('detected_at')
            ->paginate($this->perPage($validated));

        return $this->paginated($queue, fn (Detection $d) => $this->payload($d));
    }

    /**
     * Counters shown above the queue: how many are pending vs. already labelled.
     */
    public function stats(): JsonResponse
    {
        $annotatedIds = Annotation::whereNotNull('detection_id')->pluck('detection_id');

        $pending = Detection::whereNotIn('id', $annotatedIds)
            ->where(fn ($q) => $q->whereNotNull('frame_path')
                ->orWhereIn('status', array_merge(Detection::FAILED_STATUSES, ['recheck', 'returned'])))
            ->count();

        return response()->json([
            'data' => [
                'pending' => $pending,
                'labelled' => Annotation::where('status', 'approved')->count(),
            ],
        ]);
    }

    /**
     * Confirm the AI-suggested label as ground truth. Only trainable statuses
     * (real visual classes, not workflow states like "returned") can be
     * confirmed as-is — same restriction as the web screen.
     */
    public function approve(Detection $detection): JsonResponse
    {
        if (! in_array($detection->status, Detection::TRAINABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Status "'.$detection->statusLabel().'" bukan kelas visual. Relabel ke kelas yang benar dulu.'],
            ]);
        }

        $this->storeAnnotation($detection, $detection->status, 'ai');

        return response()->json(['message' => 'Label disetujui & masuk dataset.']);
    }

    /**
     * Correct the label to a different class and feed it back as training data.
     */
    public function relabel(Request $request, Detection $detection): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', Rule::in(Detection::TRAINABLE_STATUSES)],
        ]);

        $this->storeAnnotation($detection, $validated['label'], 'human');

        return response()->json([
            'message' => 'Label diperbarui ke "'.Detection::STATUSES[$validated['label']]['label'].'".',
        ]);
    }

    /**
     * Create/refresh the approved annotation for a detection, then
     * opportunistically kick off a retrain — identical to the web flow.
     */
    private function storeAnnotation(Detection $detection, string $label, string $source): void
    {
        $imagePath = $detection->frame_path ?: $detection->product?->image;

        if (! $imagePath) {
            throw ValidationException::withMessages([
                'image' => ['Tidak ada gambar untuk dilabeli pada item ini.'],
            ]);
        }

        Annotation::updateOrCreate(
            ['detection_id' => $detection->id],
            [
                'product_id' => $detection->product_id,
                'image_path' => $imagePath,
                'label' => $label,
                'bbox' => null,
                'status' => 'approved',
                'source' => $source,
                'confidence' => $detection->confidence,
            ],
        );

        app(AutoRetrain::class)->maybeTrigger();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Detection $detection): array
    {
        return [
            'id' => $detection->id,
            'code' => $detection->code,
            'status' => $detection->status,
            'status_label' => $detection->statusLabel(),
            'status_color' => $detection->statusColor(),
            'product' => $detection->product?->only(['id', 'name', 'code']),
            'image_url' => $detection->imageUrl() ?: null,
            'confidence' => $detection->confidence,
            'detected_at' => optional($detection->detected_at)->toIso8601String(),
        ];
    }
}
