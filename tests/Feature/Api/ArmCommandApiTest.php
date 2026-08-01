<?php

namespace Tests\Feature\Api;

use App\Models\RolePermission;
use App\Models\SystemLog;
use App\Models\TargetZonePreset;
use App\Models\User;
use App\Services\ArmMqttService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ArmCommandApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedPresets(): void
    {
        foreach (TargetZonePreset::defaults() as $preset) {
            TargetZonePreset::create($preset);
        }
    }

    /** Swap the MQTT service so tests never touch a broker. */
    private function fakeArm(bool $published): void
    {
        $mock = Mockery::mock(ArmMqttService::class);
        $mock->shouldReceive('publishPayload')->andReturn($published);
        $this->instance(ArmMqttService::class, $mock);
    }

    private function actingAsOperator(): User
    {
        // Baseline matrix gives operator 'w' on Live Camera.
        $user = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_it_publishes_a_command_and_records_who_sent_it(): void
    {
        $this->seedPresets();
        $this->fakeArm(published: true);
        $user = $this->actingAsOperator();

        $this->postJson('/api/arm/command', ['category' => 'Electronics'])
            ->assertOk()
            ->assertJsonPath('command.category', 'Electronics');

        // A physical movement must be traceable to the account that ordered it.
        $this->assertDatabaseHas('system_logs', ['source' => 'arm']);
        $log = SystemLog::where('source', 'arm')->firstOrFail();
        $this->assertSame($user->id, $log->context['issued_by']);
        $this->assertSame('mobile', $log->context['source']);
    }

    public function test_it_forwards_caller_context_to_the_service(): void
    {
        $this->seedPresets();

        $mock = Mockery::mock(ArmMqttService::class);
        $mock->shouldReceive('publishPayload')
            ->once()
            ->withArgs(function (array $payload) {
                return $payload['category'] === 'Cosmetics'
                    && ($payload['detection_id'] ?? null) === 42
                    && ($payload['source'] ?? null) === 'mobile';
            })
            ->andReturn(true);
        $this->instance(ArmMqttService::class, $mock);

        $this->actingAsOperator();

        $this->postJson('/api/arm/command', [
            'category' => 'Cosmetics',
            'context' => ['detection_id' => 42],
        ])->assertOk();
    }

    public function test_it_returns_503_when_the_broker_is_unreachable(): void
    {
        $this->seedPresets();
        $this->fakeArm(published: false);
        $this->actingAsOperator();

        $this->postJson('/api/arm/command', ['category' => 'Electronics'])
            ->assertStatus(503);

        // Nothing moved, so nothing should be logged as if it had.
        $this->assertDatabaseCount('system_logs', 0);
    }

    public function test_it_returns_422_when_no_presets_are_seeded(): void
    {
        // No seedPresets() here. forCategory() falls back to the "default"
        // preset, so a miss here means not even that fallback exists — a
        // client-actionable 422, not a transient broker problem.
        $this->fakeArm(published: true);
        $this->actingAsOperator();

        $this->postJson('/api/arm/command', ['category' => 'Electronics'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kategori ini tidak memiliki zona target yang dikonfigurasi.');
    }

    public function test_an_unknown_category_still_works_via_the_default_preset(): void
    {
        $this->seedPresets();
        $this->fakeArm(published: true);
        $this->actingAsOperator();

        // Deliberate: TargetZonePreset::forCategory() falls back to "default",
        // so an unrecognised category is routed rather than rejected.
        $this->postJson('/api/arm/command', ['category' => 'Kategori Antah Berantah'])
            ->assertOk();
    }

    public function test_it_validates_the_request(): void
    {
        $this->seedPresets();
        $this->fakeArm(published: true);
        $this->actingAsOperator();

        $this->postJson('/api/arm/command', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);

        $this->postJson('/api/arm/command', ['category' => 'Electronics', 'context' => 'bukan-array'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['context']);
    }

    public function test_a_viewer_cannot_move_the_arm(): void
    {
        // Baseline matrix gives viewer read-only on Live Camera.
        $this->seedPresets();
        $this->fakeArm(published: true);
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer', 'is_active' => true]));

        $this->postJson('/api/arm/command', ['category' => 'Electronics'])
            ->assertStatus(403);

        $this->assertDatabaseCount('system_logs', 0);
    }

    public function test_a_deactivated_account_cannot_move_the_arm(): void
    {
        $this->seedPresets();
        $this->fakeArm(published: true);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]));

        $this->postJson('/api/arm/command', ['category' => 'Electronics'])
            ->assertStatus(403);
    }

    public function test_a_stored_override_can_revoke_arm_access(): void
    {
        RolePermission::updateOrCreate(
            ['role' => 'operator', 'module' => 'Live Camera'],
            ['access' => 'r'],
        );

        $this->seedPresets();
        $this->fakeArm(published: true);
        $this->actingAsOperator();

        $this->postJson('/api/arm/command', ['category' => 'Electronics'])
            ->assertStatus(403);
    }

    public function test_guest_cannot_move_the_arm(): void
    {
        $this->postJson('/api/arm/command', ['category' => 'Electronics'])
            ->assertStatus(401);
    }
}
