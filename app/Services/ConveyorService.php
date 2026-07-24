<?php

namespace App\Services;

use App\Models\SystemLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * MQTT control + telemetry for the conveyor line, mirroring {@see ArmMqttService}
 * (Opsi A, broker hosted separately; the conveyor controller — PLC/ESP32 —
 * subscribes to "conveyor/command" directly). All one-shot calls are
 * best-effort: broker failures are caught so screens/endpoints degrade to
 * "conveyor offline" instead of throwing.
 *
 * Topics (under the conveyor base prefix, default "conveyor"):
 *   - conveyor/command  <- start | stop | reverse | speed  (published here)
 *   - conveyor/status   -> line telemetry                  (consumed elsewhere)
 *   - conveyor/alert    <- jam | off_flow anomalies         (published here)
 */
class ConveyorService
{
    private const QOS_COMMAND = MqttClient::QOS_AT_LEAST_ONCE;

    /** Recognised conveyor commands. */
    public const COMMANDS = ['start', 'stop', 'reverse', 'speed'];

    /** Anomaly event types raised from off-flow analysis. */
    public const EVENTS = ['jam', 'off_flow'];

    public function newClient(?string $clientIdSuffix = null): MqttClient
    {
        $clientId = trim((string) config('services.mqtt.client_id_prefix', 'sortvision'))
            .'-conv-'.($clientIdSuffix ?: Str::random(6));

        return new MqttClient(
            (string) config('services.mqtt.host', '127.0.0.1'),
            (int) config('services.mqtt.port', 1883),
            $clientId,
        );
    }

    public function connectionSettings(): ConnectionSettings
    {
        return (new ConnectionSettings)
            ->setUsername(config('services.mqtt.username'))
            ->setPassword(config('services.mqtt.password'))
            ->setConnectTimeout((int) config('services.mqtt.connect_timeout', 3))
            ->setUseTls((bool) config('services.mqtt.use_tls', false));
    }

    public function baseTopic(): string
    {
        return trim((string) config('services.mqtt.conveyor_base_topic', 'conveyor'), '/');
    }

    public function commandTopic(): string
    {
        return $this->baseTopic().'/command';
    }

    public function statusTopic(): string
    {
        return $this->baseTopic().'/status';
    }

    public function alertTopic(): string
    {
        return $this->baseTopic().'/alert';
    }

    /**
     * Publish a control command ("start"/"stop"/"reverse"/"speed") to the line.
     * Best-effort: returns false for an unknown command or an offline broker.
     *
     * @param  array<string, mixed>  $context  extra fields (e.g. speed_rpm, line)
     */
    public function command(string $command, array $context = []): bool
    {
        if (! in_array($command, self::COMMANDS, true)) {
            Log::warning('Conveyor command rejected: unknown command', ['command' => $command]);

            return false;
        }

        return $this->publish($this->commandTopic(), array_merge($context, [
            'command' => $command,
            'issued_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * Raise a conveyor anomaly (jam / off_flow): record a SystemLog and publish
     * to "conveyor/alert" so downstream controllers can react (e.g. auto-stop).
     * Returns the created SystemLog id.
     *
     * @param  array<string, mixed>  $metrics  flow metrics for context
     */
    public function raiseAlert(string $event, ?string $conveyor, array $metrics = []): int
    {
        $known = in_array($event, self::EVENTS, true);
        $line = $conveyor ?: 'LINE-?';

        $log = SystemLog::create([
            'level' => $event === 'jam' ? 'error' : 'warning',
            'source' => 'conveyor',
            'message' => $known
                ? "Conveyor {$event} detected on {$line}."
                : "Conveyor anomaly '{$event}' on {$line}.",
            'context' => array_merge(['event' => $event, 'conveyor' => $conveyor], $metrics),
            'logged_at' => now(),
        ]);

        $this->publish($this->alertTopic(), [
            'event' => $event,
            'conveyor' => $conveyor,
            'log_id' => $log->id,
            'metrics' => $metrics,
            'raised_at' => now()->toIso8601String(),
        ]);

        return $log->id;
    }

    /**
     * Is the MQTT broker reachable? Best-effort.
     */
    public function isConnected(): bool
    {
        try {
            $client = $this->newClient('ping');
            $client->connect($this->connectionSettings(), true);
            $connected = $client->isConnected();
            $client->disconnect();

            return $connected;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function publish(string $topic, array $payload): bool
    {
        try {
            $client = $this->newClient('pub');
            $client->connect($this->connectionSettings(), true);
            $client->publish($topic, json_encode($payload, JSON_UNESCAPED_SLASHES), self::QOS_COMMAND);
            $client->disconnect();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Conveyor MQTT publish failed', ['topic' => $topic, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
