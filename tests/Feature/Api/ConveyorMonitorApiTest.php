<?php

namespace Tests\Feature\Api;

use App\Models\RolePermission;
use App\Models\SystemLog;
use App\Models\User;
use App\Services\ConveyorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConveyorMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRolePermissions(): void
    {
        foreach (RolePermission::defaults() as $role => $modules) {
            foreach ($modules as $module => $access) {
                RolePermission::updateOrCreate(
                    ['role' => $role, 'module' => $module],
                    ['access' => $access],
                );
            }
        }
    }

    private function actingAsRole(string $role = 'admin'): User
    {
        $this->seedRolePermissions();
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Alerts are SystemLog rows, not their own table — build them the way
     * ConveyorService::raiseAlert() does.
     */
    private function alert(string $event, string $line = 'LINE-A', array $metrics = []): SystemLog
    {
        return SystemLog::create([
            'level' => $event === 'jam' ? 'error' : 'warning',
            'source' => 'conveyor',
            'message' => "Conveyor {$event} detected on {$line}.",
            'context' => array_merge(['event' => $event, 'conveyor' => $line], $metrics),
            'logged_at' => now(),
        ]);
    }

    /** The broker is never reachable in tests; stub it so status() is fast. */
    private function stubBroker(bool $connected = true): void
    {
        $this->mock(ConveyorService::class, function ($mock) use ($connected) {
            $mock->shouldReceive('isConnected')->andReturn($connected);
        });
    }

    public function test_guest_cannot_read_conveyor_status(): void
    {
        $this->getJson('/api/conveyor/status')->assertStatus(401);
    }

    public function test_status_reports_broker_and_counts(): void
    {
        $this->actingAsRole();
        $this->stubBroker(true);

        $this->alert('jam');
        $this->alert('off_flow');
        $this->alert('off_flow');

        $this->getJson('/api/conveyor/status')
            ->assertOk()
            ->assertJsonPath('data.broker_connected', true)
            ->assertJsonPath('data.counts.jam', 1)
            ->assertJsonPath('data.counts.off_flow', 2)
            ->assertJsonPath('data.total_alerts', 3)
            ->assertJsonPath('data.latest_alert.event', 'off_flow');
    }

    public function test_status_handles_a_line_with_no_alerts(): void
    {
        $this->actingAsRole();
        $this->stubBroker(false);

        $this->getJson('/api/conveyor/status')
            ->assertOk()
            ->assertJsonPath('data.broker_connected', false)
            ->assertJsonPath('data.total_alerts', 0)
            ->assertJsonPath('data.latest_alert', null);
    }

    public function test_status_counters_ignore_alerts_older_than_the_window(): void
    {
        $this->actingAsRole();
        $this->stubBroker();

        $old = $this->alert('jam');
        $old->update(['logged_at' => now()->subDays(3)]);

        $this->getJson('/api/conveyor/status')
            ->assertOk()
            ->assertJsonPath('data.counts.jam', 0)
            // The latest alert is not window-bound — it is the last known state.
            ->assertJsonPath('data.latest_alert.event', 'jam');
    }

    public function test_alerts_lists_only_conveyor_logs(): void
    {
        $this->actingAsRole();

        $this->alert('jam');
        SystemLog::factory()->create(['source' => 'camera', 'logged_at' => now()]);

        $response = $this->getJson('/api/conveyor/alerts')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('jam', $response->json('data.0.event'));
    }

    public function test_alerts_can_be_filtered_by_event(): void
    {
        $this->actingAsRole();

        $this->alert('jam');
        $this->alert('off_flow');

        $response = $this->getJson('/api/conveyor/alerts?event=jam')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('jam', $response->json('data.0.event'));
    }

    public function test_alerts_rejects_an_unknown_event_filter(): void
    {
        $this->actingAsRole();

        $this->getJson('/api/conveyor/alerts?event=explosion')
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }

    public function test_alert_payload_separates_metrics_from_the_event_keys(): void
    {
        $this->actingAsRole();

        $this->alert('off_flow', 'LINE-B', ['speed' => 0.2, 'camera' => 'CAM-01']);

        $response = $this->getJson('/api/conveyor/alerts')->assertOk();

        $this->assertSame('LINE-B', $response->json('data.0.conveyor'));
        // 'event'/'conveyor' are surfaced as own fields, not duplicated here.
        $this->assertSame(['speed' => 0.2, 'camera' => 'CAM-01'], $response->json('data.0.metrics'));
    }

    public function test_it_dispatches_a_conveyor_command(): void
    {
        $this->actingAsRole();

        $this->mock(ConveyorService::class, function ($mock) {
            $mock->shouldReceive('command')
                ->once()
                ->with('start', [])
                ->andReturnTrue();
        });

        $this->postJson('/api/conveyor/command', ['command' => 'start'])
            ->assertOk()
            ->assertJsonPath('data.command', 'start');
    }

    public function test_it_forwards_speed_context_with_the_command(): void
    {
        $this->actingAsRole();

        $this->mock(ConveyorService::class, function ($mock) {
            $mock->shouldReceive('command')
                ->once()
                ->with('speed', ['speed_rpm' => 120])
                ->andReturnTrue();
        });

        $this->postJson('/api/conveyor/command', ['command' => 'speed', 'speed_rpm' => 120])
            ->assertOk();
    }

    public function test_command_returns_503_when_the_broker_is_offline(): void
    {
        $this->actingAsRole();

        $this->mock(ConveyorService::class, function ($mock) {
            $mock->shouldReceive('command')->once()->andReturnFalse();
        });

        $this->postJson('/api/conveyor/command', ['command' => 'stop'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Broker MQTT sedang offline. Coba lagi nanti.');
    }

    public function test_command_rejects_an_unknown_command(): void
    {
        $this->actingAsRole();

        $this->postJson('/api/conveyor/command', ['command' => 'explode'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('command');
    }

    public function test_a_read_only_role_cannot_command_the_line(): void
    {
        $user = $this->actingAsRole();
        RolePermission::where('role', $user->role)
            ->where('module', 'Live Camera')
            ->update(['access' => 'r']);

        $this->postJson('/api/conveyor/command', ['command' => 'stop'])->assertStatus(403);
    }

    public function test_a_role_without_live_camera_cannot_read_alerts(): void
    {
        $user = $this->actingAsRole();
        RolePermission::where('role', $user->role)
            ->where('module', 'Live Camera')
            ->update(['access' => '-']);

        $this->getJson('/api/conveyor/alerts')->assertStatus(403);
    }
}
