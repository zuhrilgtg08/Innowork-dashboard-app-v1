<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\ArmMqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class StatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_reports_online_when_broker_connected(): void
    {
        $this->mock(ArmMqttService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isConnected')->once()->andReturn(true);
        });

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/status')
            ->assertOk()
            ->assertJsonPath('status', 'online')
            ->assertJsonPath('mqtt_connected', true)
            ->assertJsonStructure(['status', 'mqtt_connected', 'app_name', 'timezone', 'timestamp']);
    }

    public function test_status_reports_offline_when_broker_unreachable(): void
    {
        $this->mock(ArmMqttService::class, function (MockInterface $mock) {
            $mock->shouldReceive('isConnected')->once()->andReturn(false);
        });

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/status')
            ->assertOk()
            ->assertJsonPath('status', 'offline')
            ->assertJsonPath('mqtt_connected', false);
    }

    public function test_guest_cannot_access_status(): void
    {
        $this->getJson('/api/status')->assertStatus(401);
    }
}
