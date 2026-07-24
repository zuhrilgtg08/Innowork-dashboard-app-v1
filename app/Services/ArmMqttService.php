<?php

namespace App\Services;

use App\Models\Detection;
use App\Models\TargetZonePreset;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * Thin wrapper around the MQTT broker (Mosquitto/EMQX) for the robotic arm
 * (Opsi A, no Jetson). Like {@see MlClient}, all one-shot calls are best-effort:
 * transport failures are caught so screens/endpoints degrade gracefully (e.g.
 * show "MQTT broker offline") instead of throwing 500s.
 *
 * The broker is hosted separately; the ESP32 connects to the same broker over
 * WiFi and subscribes to "arm/command" directly (no serial hop through a Jetson).
 * Laravel is the publisher of commands and — via the mqtt:listen command — a
 * consumer of "arm/status" / "arm/detection" telemetry.
 */
class ArmMqttService
{
    /**
     * QoS 1 (at least once) for commands: the ESP32 talks to the broker over
     * WiFi, which is less reliable than a wired link, so we don't want a
     * fire-and-forget command to be silently dropped.
     */
    private const QOS_COMMAND = MqttClient::QOS_AT_LEAST_ONCE;

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
     * Build the final "arm/command" payload for a product category: look up its
     * {@see TargetZonePreset} and emit ready-to-run joint angles (the ESP32 does
     * not compute inverse kinematics itself). Returns null if no preset matches
     * and there is no default fallback.
     *
     * @param  array<string, mixed>  $context  extra fields (e.g. detection_id, source)
     * @return array<string, mixed>|null
     */
    public function buildCommandPayload(string $category, array $context = []): ?array
    {
        $preset = TargetZonePreset::forCategory($category);

        if (! $preset) {
            return null;
        }

        // Core keys always win over caller-supplied context.
        return array_merge($context, [
            'category' => $category,
            'zone' => $preset->slug,
            'joint_angles' => $preset->joint_angles,
            'issued_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Publish a command for a product category to "arm/command" (subscribed
     * directly by the ESP32). The payload carries resolved joint angles from
     * the category's preset. Best-effort: returns false if no preset matched or
     * the broker was unreachable.
     *
     * @param  array<string, mixed>  $context  extra fields merged into the payload
     */
    public function publishCommand(string $category, array $context = []): bool
    {
        $payload = $this->buildCommandPayload($category, $context);

        if ($payload === null) {
            Log::warning('MQTT publishCommand: no target-zone preset', ['category' => $category]);

            return false;
        }

        return $this->publishPayload($payload);
    }

    /**
     * Route an auto-rejected defect to the return/reject zone: resolve the
     * return {@see TargetZonePreset} and publish its joint angles to
     * "arm/command", stamped with the detection's context so the operator (and
     * the ESP32 logs) can trace which item was diverted. Best-effort.
     */
    public function routeToReturn(Detection $detection): bool
    {
        $preset = TargetZonePreset::forReturn();

        if (! $preset) {
            Log::warning('MQTT routeToReturn: no return-zone preset seeded');

            return false;
        }

        return $this->publishPayload([
            'action' => 'return',
            'detection_id' => $detection->id,
            'code' => $detection->code,
            'status' => $detection->status,
            'qr_value' => $detection->qr_value,
            'conveyor' => $detection->conveyor,
            'zone' => $preset->slug,
            'joint_angles' => $preset->joint_angles,
            'issued_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Publish a prepared payload to "arm/command" (QoS 1). Best-effort: broker
     * transport failures are caught and reported as false.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function publishPayload(array $payload): bool
    {
        try {
            $client = $this->newClient('pub');
            $client->connect($this->connectionSettings(), true);
            $client->publish($this->commandTopic(), json_encode($payload, JSON_UNESCAPED_SLASHES), self::QOS_COMMAND);
            $client->disconnect();

            return true;
        } catch (\Throwable $e) {
            Log::warning('MQTT publish failed', ['error' => $e->getMessage()]);

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
