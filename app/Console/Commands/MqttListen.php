<?php

namespace App\Console\Commands;

use App\Models\ArmStatus;
use App\Models\Detection;
use App\Models\SortingEvent;
use App\Models\SortingSession;
use App\Services\ArmMqttService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PhpMqtt\Client\MqttClient;

/**
 * Long-running MQTT consumer for the robotic arm (Opsi A). Subscribes to the
 * broker's telemetry topics and writes them back into the app's existing
 * models:
 *
 *   - "arm/status"     -> updates the ArmStatus singleton (idle/running/error + competition states)
 *   - "arm/detection"  -> creates a Detection row (reusing Detection::STATUSES)
 *   - "arm/command"    -> creates a SortingEvent(pending) in the active session (competition mode)
 *
 * A Laravel HTTP request can't hold a persistent MQTT subscription open, so
 * this runs as a blocking process — keep it alive with supervisor/systemd
 * (see SETUP.md). It mirrors how StartTrainingRun writes to the DB, but as a
 * loop rather than a one-shot job.
 */
class MqttListen extends Command
{
    protected $signature = 'mqtt:listen';

    protected $description = 'Subscribe to arm MQTT telemetry (status + detections + commands) and persist it';

    public function handle(ArmMqttService $mqtt): int
    {
        try {
            $client = $mqtt->newClient('listener');
            $client->connect($mqtt->connectionSettings(), true);
        } catch (\Throwable $e) {
            // Best-effort, same posture as the rest of the MQTT integration:
            // report the broker is offline instead of blowing up.
            $this->error('MQTT broker offline: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Connected to MQTT broker.');
        $this->subscribeAll($client, $mqtt);

        $this->info("Listening on '{$mqtt->statusTopic()}', '{$mqtt->detectionTopic()}', '{$mqtt->commandTopic()}'. Press Ctrl+C to stop.");

        // Blocking loop until the process is interrupted (SIGINT/SIGTERM).
        // Reconnect on transport drops (broker restart, keep-alive timeout)
        // so the daemon survives without waiting for supervisor to restart it.
        while (true) {
            try {
                $client->loop(true);

                break;
            } catch (\Throwable $e) {
                $this->warn('MQTT connection lost, reconnecting: '.$e->getMessage());
                sleep(3);

                try {
                    $client = $mqtt->newClient('listener');
                    $client->connect($mqtt->connectionSettings(), true);
                } catch (\Throwable $reconnectError) {
                    $this->error('MQTT broker offline: '.$reconnectError->getMessage());

                    return self::FAILURE;
                }

                $this->subscribeAll($client, $mqtt);
                $this->info('Reconnected to MQTT broker.');
            }
        }

        $client->disconnect();

        return self::SUCCESS;
    }

    /**
     * (Re)subscribe to all telemetry topics after (re)connect.
     */
    protected function subscribeAll(MqttClient $client, ArmMqttService $mqtt): void
    {
        $client->subscribe($mqtt->statusTopic(), fn (string $topic, string $message) => $this->handleStatus($message), 0);
        $client->subscribe($mqtt->detectionTopic(), fn (string $topic, string $message) => $this->handleDetection($message), 0);
        $client->subscribe($mqtt->commandTopic(), fn (string $topic, string $message) => $this->handleCommand($message), 0);
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

        // Normalize state to lowercase for competition states; ArmStatus::STATES has both
        $rawState = $data['state'] ?? 'idle';
        $state = strtolower((string) $rawState);

        // Only accept known states; anything unexpected falls back to 'idle'.
        if (! array_key_exists($state, ArmStatus::STATES)) {
            $this->warn("Unknown arm state '{$rawState}', falling back to 'idle'.");
            $state = 'idle';
        }

        ArmStatus::current()->update([
            'state' => $state,
            'detail' => $data['detail'] ?? null,
            'last_command' => $data['last_command'] ?? null,
            'telemetry' => $data['telemetry'] ?? null,
            'reported_at' => now(),
        ]);

        $this->line("[status] {$state}");

        // If state is COMPLETED and event_uuid present, complete the SortingEvent
        if ($state === 'completed' && isset($data['event_uuid'])) {
            $this->completeSortingEvent($data['event_uuid']);
        }
    }

    /**
     * Persist an "arm/detection" telemetry frame as a Detection row.
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

    /**
     * Handle "arm/command" from ml-service — create a SortingEvent(pending)
     * in the active session. Idempotent on event_uuid (QoS 1 redelivery guard).
     */
    protected function handleCommand(string $message): void
    {
        $data = json_decode($message, true);

        if (! is_array($data)) {
            $this->warn('Ignoring non-JSON arm/command payload.');

            return;
        }

        if (($data['action'] ?? '') !== 'sort') {
            // ignore non-sort actions (reset_session, error, etc.)
            return;
        }

        $eventUuid = $data['event_uuid'] ?? null;
        if (! $eventUuid) {
            $this->warn('arm/command missing event_uuid, ignoring.');

            return;
        }

        // Idempotency: skip if we already processed this event_uuid
        if (SortingEvent::where('event_uuid', $eventUuid)->exists()) {
            $this->line("[command] Duplicate event_uuid {$eventUuid}, skipping.");

            return;
        }

        // Normalize color to canonical green/yellow/red
        $color = $this->normalizeColor($data['color'] ?? null);
        if ($color === null) {
            $this->warn("arm/command has invalid color: {$data['color']}");

            return;
        }

        $detectionId = $data['detection_id'] ?? null;
        if (! $detectionId) {
            $this->warn('arm/command missing detection_id, ignoring.');

            return;
        }

        // Resolve or create active session
        $session = SortingSession::current();

        SortingEvent::create([
            'event_uuid' => $eventUuid,
            'detection_id' => $detectionId,
            'sorting_session_id' => $session->id,
            'status' => 'pending',
            'color' => $color,
            'destination' => $data['destination'] ?? 'BOWL_'.strtoupper($color),
            'confidence' => $data['confidence'] ?? 0,
            'command_payload' => $data,
            'source' => $data['source'] ?? 'ml_service',
            'processed' => false,
        ]);

        $this->line("[command] Created SortingEvent {$eventUuid} (color={$color}, session={$session->session_key})");
    }

    /**
     * Mark a SortingEvent as completed when arm/status COMPLETED arrives.
     */
    protected function completeSortingEvent(string $eventUuid): void
    {
        $event = SortingEvent::where('event_uuid', $eventUuid)->first();

        if (! $event) {
            $this->warn("COMPLETED received for unknown event_uuid {$eventUuid}");

            return;
        }

        if ($event->status === 'completed') {
            $this->line("[status] Event {$eventUuid} already completed.");

            return;
        }

        $event->update([
            'status' => 'completed',
            'processed' => true,
        ]);

        $this->info("[status] Completed SortingEvent {$eventUuid} (color={$event->color})");
    }

    /**
     * Normalize incoming color to canonical green/yellow/red.
     * Accepts HIJAU/KUNING/MERAH (YOLO classes) or green/yellow/red.
     */
    protected function normalizeColor(?string $color): ?string
    {
        if (! $color) {
            return null;
        }

        $color = strtolower($color);

        // Already canonical
        if (in_array($color, ['green', 'yellow', 'red'], true)) {
            return $color;
        }

        // YOLO class names → canonical
        return match ($color) {
            'hijau' => 'green',
            'kuning' => 'yellow',
            'merah' => 'red',
            default => null,
        };
    }
}
