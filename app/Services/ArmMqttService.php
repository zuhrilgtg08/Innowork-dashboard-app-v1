<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * Thin wrapper around the MQTT broker (Mosquitto/EMQX) for the robotic arm
 * (Opsi A). Like {@see MlClient}, all one-shot calls are best-effort: transport
 * failures are caught so screens/endpoints degrade gracefully (e.g. show
 * "MQTT broker offline") instead of throwing 500s.
 *
 * The broker is hosted separately; Laravel is only a publisher (dashboard/API
 * commands to "arm/command") and — via the mqtt:listen command — a consumer of
 * "arm/status" / "arm/detection" telemetry.
 */
class ArmMqttService
{
    /**
     * Build an unconnected MQTT client. The caller connects/disconnects so this
     * can back both one-shot publishes and the long-running listener loop.
     */
    public function newClient(?string $clientIdSuffix = null): MqttClient
    {
        $clientId = trim((string) config('services.mqtt.client_id_prefix', 'sortvision'))
            .'-'.($clientIdSuffix ?: Str::random(6));

        return new MqttClient(
            (string) config('services.mqtt.host', '127.0.0.1'),
            (int) config('services.mqtt.port', 1883),
            $clientId,
        );
    }

    /**
     * Broker connection settings derived from config/services.php (env-driven).
     */
    public function connectionSettings(): ConnectionSettings
    {
        return (new ConnectionSettings)
            ->setUsername(config('services.mqtt.username'))
            ->setPassword(config('services.mqtt.password'))
            ->setConnectTimeout((int) config('services.mqtt.connect_timeout', 3))
            ->setUseTls((bool) config('services.mqtt.use_tls', false));
    }

    /**
     * The base topic all arm topics hang off, e.g. "arm".
     */
    public function baseTopic(): string
    {
        return trim((string) config('services.mqtt.base_topic', 'arm'), '/');
    }

    public function commandTopic(): string
    {
        return $this->baseTopic().'/command';
    }

    public function statusTopic(): string
    {
        return $this->baseTopic().'/status';
    }

    public function detectionTopic(): string
    {
        return $this->baseTopic().'/detection';
    }

    /**
     * Publish a JSON command to the broker (typically "arm/command"). Returns
     * true if the broker accepted it, false if the broker was unreachable.
     *
     * @param  array<string, mixed>  $payload
     */
    public function publishCommand(string $topic, array $payload): bool
    {
        try {
            $client = $this->newClient('pub');
            $client->connect($this->connectionSettings(), true);
            $client->publish($topic, json_encode($payload, JSON_UNESCAPED_SLASHES), 0);
            $client->disconnect();

            return true;
        } catch (\Throwable $e) {
            Log::warning('MQTT publishCommand failed', ['topic' => $topic, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Is the MQTT broker reachable? Used by GET /api/status. Best-effort: a
     * failed connection just reports offline rather than throwing.
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
}
