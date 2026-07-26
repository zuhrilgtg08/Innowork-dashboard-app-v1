<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The settings singleton — REST counterpart of
 * `App\Livewire\Settings\Index`.
 *
 * Updates are partial: only the keys present in the request are written, so a
 * mobile client can toggle one switch without having to send the whole form.
 */
class SettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload(Setting::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_name' => ['sometimes', 'required', 'string', 'max:100'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'confidence_threshold' => ['sometimes', 'required', 'numeric', 'min:0.5', 'max:1'],
            'auto_retrain' => ['sometimes', 'boolean'],
            'email_alerts' => ['sometimes', 'boolean'],
            'auto_reject_on_damage' => ['sometimes', 'boolean'],
            'camera_source' => ['sometimes', 'required', 'in:webcam,icam'],
            'icam_rtsp_url' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($data === []) {
            return response()->json([
                'message' => 'Tidak ada perubahan yang dikirim.',
                'data' => $this->payload(Setting::current()),
            ]);
        }

        // Cast to string so the decimal:3 column keeps its precision.
        if (array_key_exists('confidence_threshold', $data)) {
            $data['confidence_threshold'] = (string) $data['confidence_threshold'];
        }

        $setting = Setting::current();
        $setting->update($data);

        // Setting::current() is cached; the model's saved() hook busts it, so
        // re-reading here returns the freshly written row.
        return response()->json([
            'message' => 'Pengaturan berhasil disimpan.',
            'data' => $this->payload(Setting::current()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Setting $setting): array
    {
        return [
            'app_name' => $setting->app_name,
            'timezone' => $setting->timezone,
            'confidence_threshold' => (float) $setting->confidence_threshold,
            'auto_retrain' => (bool) $setting->auto_retrain,
            'email_alerts' => (bool) $setting->email_alerts,
            'auto_reject_on_damage' => (bool) $setting->auto_reject_on_damage,
            'camera_source' => $setting->camera_source,
            'icam_rtsp_url' => $setting->icam_rtsp_url,
            'active_training_run_id' => $setting->active_training_run_id,
            'updated_at' => optional($setting->updated_at)->toIso8601String(),
        ];
    }
}
