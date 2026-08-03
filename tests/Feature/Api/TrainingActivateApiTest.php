<?php

namespace Tests\Feature\Api;

use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\TrainingRun;
use App\Models\User;
use App\Services\MlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrainingActivateApiTest extends TestCase
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

    /** The ML service is never running in tests; stub the hot-reload call. */
    private function stubMl(bool $reloaded = true): void
    {
        $this->mock(MlClient::class, function ($mock) use ($reloaded) {
            $mock->shouldReceive('reloadModel')->andReturn($reloaded);
        });
    }

    public function test_guest_cannot_activate_a_model(): void
    {
        $run = TrainingRun::factory()->completed()->create();

        $this->postJson("/api/training-runs/{$run->id}/activate")->assertStatus(401);
    }

    public function test_it_activates_a_completed_run(): void
    {
        $this->actingAsRole();
        $this->stubMl();
        $run = TrainingRun::factory()->completed()->create();

        $this->postJson("/api/training-runs/{$run->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.active_training_run_id', $run->id)
            ->assertJsonPath('data.ml_reloaded', true);

        $this->assertSame($run->id, Setting::current()->active_training_run_id);
    }

    public function test_activation_survives_an_offline_ml_service(): void
    {
        $this->actingAsRole();
        $this->stubMl(false);
        $run = TrainingRun::factory()->completed()->create();

        // The setting is the source of truth — a failed hot-reload means
        // "service offline", not "activation failed".
        $this->postJson("/api/training-runs/{$run->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.ml_reloaded', false);

        $this->assertSame($run->id, Setting::current()->active_training_run_id);
    }

    public function test_it_refuses_a_run_that_has_not_finished(): void
    {
        $this->actingAsRole();
        $run = TrainingRun::factory()->create(['status' => 'training']);

        $this->postJson("/api/training-runs/{$run->id}/activate")->assertStatus(422);

        $this->assertNull(Setting::current()->active_training_run_id);
    }

    public function test_it_refuses_a_run_without_a_stored_model(): void
    {
        $this->actingAsRole();
        $run = TrainingRun::factory()->completed()->create(['model_path' => null]);

        $this->postJson("/api/training-runs/{$run->id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Run ini tidak punya model tersimpan, tidak bisa diaktifkan.');
    }

    public function test_it_refuses_a_run_below_the_quality_bar(): void
    {
        $this->actingAsRole();
        config()->set('services.ml.min_map', 90);
        $run = TrainingRun::factory()->completed()->create(['metrics' => ['map50' => 40.0]]);

        $this->postJson("/api/training-runs/{$run->id}/activate")->assertStatus(422);

        $this->assertNull(Setting::current()->active_training_run_id);
    }

    public function test_force_overrides_the_quality_bar(): void
    {
        $this->actingAsRole();
        $this->stubMl();
        config()->set('services.ml.min_map', 90);
        $run = TrainingRun::factory()->completed()->create(['metrics' => ['map50' => 40.0]]);

        $this->postJson("/api/training-runs/{$run->id}/activate", ['force' => true])->assertOk();

        $this->assertSame($run->id, Setting::current()->active_training_run_id);
    }

    public function test_force_does_not_override_the_completed_check(): void
    {
        $this->actingAsRole();
        $run = TrainingRun::factory()->create(['status' => 'failed']);

        // Forcing past the quality bar must not activate a run that produced
        // no model at all.
        $this->postJson("/api/training-runs/{$run->id}/activate", ['force' => true])
            ->assertStatus(422);

        $this->assertNull(Setting::current()->active_training_run_id);
    }

    public function test_activation_is_recorded_in_the_system_log(): void
    {
        $this->actingAsRole();
        $this->stubMl();
        $run = TrainingRun::factory()->completed()->create();

        $this->postJson("/api/training-runs/{$run->id}/activate")->assertOk();

        $this->assertDatabaseHas('system_logs', [
            'source' => 'ai',
            'message' => "Model {$run->name} diaktifkan untuk inference.",
        ]);
    }

    public function test_the_run_list_marks_which_model_is_live(): void
    {
        $this->actingAsRole();
        $this->stubMl();
        $run = TrainingRun::factory()->completed()->create();
        $other = TrainingRun::factory()->completed()->create();

        $this->postJson("/api/training-runs/{$run->id}/activate")->assertOk();

        $data = collect($this->getJson('/api/training-runs')->json('data'))->keyBy('id');

        $this->assertTrue($data[$run->id]['is_active_model']);
        $this->assertFalse($data[$other->id]['is_active_model']);
    }

    public function test_a_read_only_role_cannot_activate(): void
    {
        $user = $this->actingAsRole();
        RolePermission::where('role', $user->role)
            ->where('module', 'Training')
            ->update(['access' => 'r']);
        $run = TrainingRun::factory()->completed()->create();

        $this->postJson("/api/training-runs/{$run->id}/activate")->assertStatus(403);
    }
}
