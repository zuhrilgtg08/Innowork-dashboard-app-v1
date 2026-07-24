<?php

namespace App\Services;

use App\Models\Detection;
use App\Models\ReturnBatch;
use App\Models\Setting;
use App\Models\SystemLog;
use Illuminate\Support\Collection;

/**
 * Turns QC verdicts into physical/administrative action. When auto-reject is on,
 * defective detections from a frame are grouped into the conveyor's open
 * {@see ReturnBatch} and the robotic arm is commanded to divert the item to the
 * return zone. Called from the ingest points (CameraController, LiveCamera).
 */
class QcWorkflow
{
    public function __construct(private ArmMqttService $arm) {}

    /**
     * Process all detections produced by one frame. No-op unless auto-reject is
     * enabled and the frame contains at least one defect. All defects in a frame
     * belong to the same physical item, so they share one return batch and a
     * single arm command.
     *
     * @param  iterable<int, Detection>  $detections
     * @return ReturnBatch|null  the batch defects were attached to, if any
     */
    public function handleFrame(iterable $detections): ?ReturnBatch
    {
        $setting = Setting::current();

        if (! $setting->auto_reject_on_damage) {
            return null;
        }

        $defects = Collection::make($detections)
            ->filter(fn (Detection $d) => in_array($d->status, Detection::FAILED_STATUSES, true));

        if ($defects->isEmpty()) {
            return null;
        }

        /** @var Detection $first */
        $first = $defects->first();

        $batch = ReturnBatch::openForConveyor($first->conveyor);

        Detection::whereIn('id', $defects->pluck('id'))
            ->update(['return_batch_id' => $batch->id]);

        $armCommanded = $this->arm->routeToReturn($first);

        SystemLog::create([
            'level' => 'warning',
            'source' => 'arm',
            'message' => "Auto-reject: {$defects->count()} defect(s) routed to return batch #{$batch->id} on {$first->conveyor}. Arm "
                .($armCommanded ? 'commanded' : 'offline').'.',
            'context' => [
                'batch_id' => $batch->id,
                'detection_ids' => $defects->pluck('id')->all(),
                'arm_commanded' => $armCommanded,
            ],
            'logged_at' => now(),
        ]);

        return $batch;
    }
}
