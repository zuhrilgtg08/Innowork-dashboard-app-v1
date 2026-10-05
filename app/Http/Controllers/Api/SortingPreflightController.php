<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArmStatus;
use App\Models\SortingEvent;
use App\Models\SortingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pre-sort gate for the ml-service sort pipeline (H-1 hardening).
 *
 * Called by sort_pipeline BEFORE publishing arm/command. The Python service
 * cannot read Laravel's database directly, so this endpoint answers the two
 * questions it must ask:
 *
 *   1. Bowl capacity — has the target bowl already reached
 *      SORTING_MAX_OBJECTS_PER_COLOR completed objects? (§8)
 *   2. Arm availability — is the arm in a state that accepts a new command,
 *      or is it busy/error? (§7)
 *
 * Authenticated the same way as ingest (verify.ml HMAC) — the caller is a
 * machine, not a browser. Read-only: never creates rows.
 */
class SortingPreflightController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'color' => ['required', 'string', Rule::in(['green', 'yellow', 'red'])],
        ]);

        $session = SortingSession::current();
        $counts = SortingEvent::countsByColor($session->id);
        $max = (int) config('services.sorting.max_objects_per_color', 3);
        $count = (int) ($counts[$data['color']] ?? 0);

        $armState = strtolower((string) (ArmStatus::current()->state ?? 'idle'));
        $armBusy = ! in_array($armState, ['ready', 'idle', 'completed'], true);

        return response()->json([
            'ok' => true,
            'color' => $data['color'],
            'session_id' => $session->id,
            'count' => $count,
            'max' => $max,
            'bowl_full' => $count >= $max,
            'arm_state' => $armState,
            'arm_busy' => $armBusy,
        ]);
    }
}
