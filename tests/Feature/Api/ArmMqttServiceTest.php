<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\TargetZonePreset;
use App\Services\ArmMqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArmMqttServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_command_payload_with_resolved_joint_angles(): void
    {
        TargetZonePreset::create([
            'slug' => 'electronics',
            'category' => 'Electronics',
            'label' => 'Electronics Zone',
            'joint_angles' => [10, 15, 20, 25, 30, 35],
        ]);

        $payload = app(ArmMqttService::class)->buildCommandPayload('Electronics', ['detection_id' => 7]);

        $this->assertNotNull($payload);
        $this->assertSame('Electronics', $payload['category']);
        $this->assertSame('electronics', $payload['zone']);
        $this->assertSame([10, 15, 20, 25, 30, 35], $payload['joint_angles']);
        $this->assertSame(7, $payload['detection_id']);
        $this->assertArrayHasKey('issued_at', $payload);
    }

    public function test_it_falls_back_to_the_default_preset_for_unknown_category(): void
    {
        TargetZonePreset::create([
            'slug' => TargetZonePreset::DEFAULT_SLUG,
            'category' => null,
            'label' => 'Default',
            'joint_angles' => [0, 0, 0, 0, 0, 0],
        ]);

        $payload = app(ArmMqttService::class)->buildCommandPayload('Nonexistent Category');

        $this->assertNotNull($payload);
        $this->assertSame(TargetZonePreset::DEFAULT_SLUG, $payload['zone']);
    }

    public function test_it_returns_null_when_no_preset_and_no_default(): void
    {
        $this->assertNull(app(ArmMqttService::class)->buildCommandPayload('Anything'));
    }

    public function test_publish_command_degrades_gracefully_when_broker_offline(): void
    {
        TargetZonePreset::create([
            'slug' => 'electronics',
            'category' => 'Electronics',
            'label' => 'Electronics Zone',
            'joint_angles' => [10, 15, 20, 25, 30, 35],
        ]);

        // Point at a port with no broker listening — must return false, not throw.
        config(['services.mqtt.host' => '127.0.0.1', 'services.mqtt.port' => 1, 'services.mqtt.connect_timeout' => 1]);

        $this->assertFalse(app(ArmMqttService::class)->publishCommand('Electronics'));
    }

    public function test_seed_defaults_cover_every_product_category(): void
    {
        foreach (TargetZonePreset::defaults() as $preset) {
            TargetZonePreset::updateOrCreate(['slug' => $preset['slug']], $preset);
        }

        foreach (Product::CATEGORIES as $category) {
            $this->assertNotNull(
                TargetZonePreset::forCategory($category),
                "Missing preset for category: {$category}",
            );
        }
    }
}
