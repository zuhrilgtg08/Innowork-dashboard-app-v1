<?php

namespace App\Console\Commands;

use App\Models\ArmStatus;
use App\Models\Detection;
use App\Services\ArmMqttService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Long-running MQTT consumer for the robotic arm (Opsi A). Subscribes to the
 * broker's telemetry topics and writes them back into the app's existing
 * models:
 *
 *   - "arm/status"    -> updates the ArmStatus singleton (idle/running/error)
 *   - "arm/detection" -> creates a Detection row (reusing Detection::STATUSES)
 *
 * A Laravel HTTP request can't hold a persistent MQTT subscription open, so
 * this runs as a blocking process — keep it alive with supervisor/systemd
 * (see SETUP.md). It mirrors how StartTrainingRun writes to the DB, but as a
 * loop rather than a one-shot job.
 */
class MqttListen extends Command
{
    protected $signature = 'mqtt:listen';

    protected $description = 'Subscribe to arm MQTT telemetry (status + detections) and persist it';

    public function handle(ArmMqttService $mqtt): int
    {
        $client = $mqtt->newClient('listener');

        try {
            $client->connect($mqtt->connectionSettings(), true);
        } catch (\Throwable $e) {
            // Best-effort, same posture as the rest of the MQTT integration:
            // report the broker is offline instead of blowing up.
            $this->error('MQTT broker offline: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Connected to MQTT broker.');

        $client->subscribe($mqtt->statusTopic(), fn (string $topic, string $message) => $this->handleStatus($message), 0);
        $client->subscribe($mqtt->detectionTopic(), fn (string $topic, string $message) => $this->handleDetection($message), 0);

        $this->info("Listening on '{$mqtt->statusTopic()}' and '{$mqtt->detectionTopic()}'. Press Ctrl+C to stop.");

        // Blocking loop until the process is interrupted (SIGINT/SIGTERM).
        $client->loop(true);

        $client->disconnect();

        return self::SUCCESS;
    }

    /**
     * Persist an "arm/status" telemetry frame onto the ArmStatus singleton.
     */
    protected function handleStatus(string $message): void
    {
        $data = json_decode($message, true);

        if (! is_array($data)) {
            $this->warn('Ignoring non-JSON arm/status payload.');

            return;
        }

        // Only accept known states; anything unexpected falls back to 'idle'.
        $state = isset($data['state']) && array_key_exists($data['state'], ArmStatus::STATES)
            ? $data['state']
            : 'idle';

        ArmStatus::current()->update([
            'state' => $state,
            'detail' => $data['detail'] ?? null,
            'last_command' => $data['last_command'] ?? null,
            'telemetry' => $data['telemetry'] ?? null,
            'reported_at' => now(),
        ]);

        $this->line("[status] {$state}");
    }

    /**
     * Persist an "arm/detection" telemetry frame as a Detection row. The status
     * is normalised against Detection::STATUSES (unknown => 'recheck'), matching
     * the ICAM ingest path in CameraController.
     */
    protected function handleDetection(string $message): void
    {
        $data = json_decode($message, true);

        if (! is_array($data) || ! isset($data['status'])) {
            $this->warn('Ignoring malformed arm/detection payload.');

            return;
        }

        $status = array_key_exists($data['status'], Detection::STATUSES)
            ? $data['status']
            : 'recheck';

        $detection = Detection::create([
            'code' => $data['code'] ?? 'MQT-'.strtoupper(Str::random(6)),
            'product_id' => $data['product_id'] ?? null,
            'camera' => $data['camera'] ?? 'ICAM-300',
            'conveyor' => $data['conveyor'] ?? 'LINE-A',
            'status' => $status,
            'qr_value' => $data['qr_value'] ?? null,
            'confidence' => $data['confidence'] ?? 0,
            'detected_at' => now(),
        ]);

        $this->line("[detection] #{$detection->id} {$status}");
    }
}
