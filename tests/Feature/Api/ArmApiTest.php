<?php

namespace Tests\Feature\Api;

use App\Models\ArmStatus;
use App\Models\RolePermission;
use App\Models\TargetZonePreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArmApiTest extends TestCase
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

    public function test_it_returns_the_default_idle_state(): void
    {
        $this->seedRolePermissions();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->getJson('/api/arm')
            ->assertOk()
            ->assertJsonPath('state', 'idle')
            ->assertJsonPath('state_label', 'Idle')
            ->assertJsonStructure(['state', 'state_label', 'detail', 'last_command', 'telemetry', 'reported_at']);
    }

    public function test_it_reflects_the_last_reported_state(): void
    {
        $this->seedRolePermissions();

        ArmStatus::current()->update([
            'state' => 'running',
            'detail' => 'Sorting batch A',
            'last_command' => 'start',
            'reported_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->getJson('/api/arm')
            ->assertOk()
            ->assertJsonPath('state', 'running')
            ->assertJsonPath('state_label', 'Running')
            ->assertJsonPath('detail', 'Sorting batch A')
            ->assertJsonPath('last_command', 'start');
    }

    public function test_guest_cannot_access_arm_status(): void
    {
        $this->getJson('/api/arm')->assertStatus(401);
    }

    public function test_it_returns_selectable_zones(): void
    {
        $this->seedRolePermissions();

        TargetZonePreset::create([
            'slug' => 'food-beverage',
            'category' => 'Food & Beverage',
            'label' => 'Food & Beverage Zone',
            'joint_angles' => [10, 20, 30, 40, 50, 60],
        ]);
        TargetZonePreset::create([
            'slug' => TargetZonePreset::DEFAULT_SLUG,
            'category' => null,
            'label' => 'Default / Uncategorised',
            'joint_angles' => [0, 0, 0, 0, 0, 0],
        ]);
        TargetZonePreset::create([
            'slug' => TargetZonePreset::RETURN_SLUG,
            'category' => null,
            'label' => 'Return / Reject Zone',
            'joint_angles' => [180, 45, 90, 45, 90, 0],
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->getJson('/api/arm/zones')
            ->assertOk()
            ->assertJsonStructure([
                'zones' => [
                    '*' => ['slug', 'label', 'joint_angles', 'selectable'],
                ],
            ])
            ->assertJsonPath('zones.0.slug', 'food-beverage')
            ->assertJsonPath('zones.0.selectable', true)
            ->assertJsonCount(1, 'zones');
    }

    public function test_zones_requires_module_read_access(): void
    {
        $this->seedRolePermissions();

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        RolePermission::where('role', $user->role)->where('module', 'Arm')->update(['access' => '-']);
        Sanctum::actingAs($user);

        $this->getJson('/api/arm/zones')->assertStatus(403);
    }

    public function test_command_requires_valid_category(): void
    {
        $this->seedRolePermissions();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->postJson('/api/arm/command', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    public function test_command_returns_422_when_no_preset_exists(): void
    {
        $this->seedRolePermissions();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->postJson('/api/arm/command', ['category' => 'Unknown Category'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kategori ini tidak memiliki zona target yang dikonfigurasi.');
    }

    public function test_command_returns_503_when_broker_is_offline(): void
    {
        $this->seedRolePermissions();

        TargetZonePreset::create([
            'slug' => 'food-beverage',
            'category' => 'Food & Beverage',
            'label' => 'Food & Beverage Zone',
            'joint_angles' => [10, 20, 30, 40, 50, 60],
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        config()->set('services.mqtt.host', '127.0.0.1');
        config()->set('services.mqtt.port', 19999);

        $this->postJson('/api/arm/command', ['category' => 'Food & Beverage'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Broker MQTT sedang offline. Coba lagi nanti.');
    }

    public function test_command_dispatches_successfully(): void
    {
        $this->seedRolePermissions();

        TargetZonePreset::create([
            'slug' => 'food-beverage',
            'category' => 'Food & Beverage',
            'label' => 'Food & Beverage Zone',
            'joint_angles' => [10, 20, 30, 40, 50, 60],
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $this->postJson('/api/arm/command', ['category' => 'Food & Beverage'])
            ->assertOk()
            ->assertJsonStructure([
                'message',
                'command' => ['category', 'zone', 'joint_angles', 'issued_at'],
            ])
            ->assertJsonPath('command.category', 'Food & Beverage')
            ->assertJsonPath('command.zone', 'food-beverage')
            ->assertJsonPath('command.joint_angles', [10, 20, 30, 40, 50, 60]);
    }

    public function test_command_requires_module_write_access(): void
    {
        $this->seedRolePermissions();

        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        RolePermission::where('role', $user->role)->where('module', 'Arm')->update(['access' => 'r']);
        Sanctum::actingAs($user);

        $this->postJson('/api/arm/command', ['category' => 'Food & Beverage'])->assertStatus(403);
    }
}
