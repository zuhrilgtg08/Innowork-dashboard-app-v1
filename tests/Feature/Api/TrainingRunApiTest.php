<?php

namespace Tests\Feature\Api;

use App\Jobs\StartTrainingRun;
use App\Models\Annotation;
use App\Models\TrainingRun;
use App\Models\User;
use App\Services\MlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class TrainingRunApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    /** Swap the ML service for a stub so tests never hit the network. */
    private function fakeMlService(bool $healthy): void
    {
        $this->instance(
            MlClient::class,
            Mockery::mock(MlClient::class)->shouldReceive('healthy')->andReturn($healthy)->getMock(),
        );
    }

    private function approvedAnnotations(int $count): void
    {
        Annotation::factory()->count($count)->create(['status' => 'approved']);
    }

    public function test_it_lists_runs_newest_first(): void
    {
        $older = TrainingRun::factory()->create(['created_at' => now()->subDay()]);
        $newer = TrainingRun::factory()->create(['created_at' => now()]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/training-runs')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'status', 'status_label', 'is_active', 'progress', 'epochs', 'map50']], 'meta']);

        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
    }

    public function test_show_includes_raw_metrics(): void
    {
        $run = TrainingRun::factory()->create(['metrics' => ['map50' => 91.5]]);
        $this->actingAsAdmin();

        $this->getJson('/api/training-runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('data.map50', 91.5)
            ->assertJsonPath('data.metrics.map50', 91.5);
    }

    public function test_it_queues_a_run_when_all_preflight_checks_pass(): void
    {
        Queue::fake();
        $this->approvedAnnotations(4);
        $this->fakeMlService(healthy: true);
        $this->actingAsAdmin();

        $this->postJson('/api/training-runs', ['epochs' => 5])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(StartTrainingRun::class);
        $this->assertDatabaseHas('training_runs', ['status' => 'queued', 'epochs' => 5]);
        // The run is recorded in the system log, tagged as mobile-originated.
        $this->assertDatabaseHas('system_logs', ['source' => 'ai']);
    }

    public function test_it_refuses_when_there_are_too_few_approved_annotations(): void
    {
        Queue::fake();
        $this->approvedAnnotations(1);
        $this->fakeMlService(healthy: true);
        $this->actingAsAdmin();

        $this->postJson('/api/training-runs', ['epochs' => 5])->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('training_runs', 0);
    }

    public function test_it_returns_503_when_the_ml_service_is_offline(): void
    {
        Queue::fake();
        $this->approvedAnnotations(4);
        $this->fakeMlService(healthy: false);
        $this->actingAsAdmin();

        $this->postJson('/api/training-runs', ['epochs' => 5])->assertStatus(503);

        Queue::assertNothingPushed();
    }

    public function test_it_returns_409_when_a_run_is_already_active(): void
    {
        Queue::fake();
        $this->approvedAnnotations(4);
        TrainingRun::factory()->create(['status' => 'training']);
        $this->fakeMlService(healthy: true);
        $this->actingAsAdmin();

        $this->postJson('/api/training-runs', ['epochs' => 5])->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_it_validates_the_epoch_range(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/training-runs', ['epochs' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['epochs']);
    }

    public function test_the_dataset_summary_reports_readiness(): void
    {
        $this->approvedAnnotations(4);
        $this->actingAsAdmin();

        $this->getJson('/api/training-runs/dataset')
            ->assertOk()
            ->assertJsonPath('data.approved_annotations', 4)
            ->assertJsonPath('data.can_start', true)
            ->assertJsonPath('data.has_active_run', false);
    }

    public function test_an_operator_can_read_but_not_start_a_run(): void
    {
        // Baseline matrix gives operator 'r' on Training.
        Sanctum::actingAs(User::factory()->create(['role' => 'operator', 'is_active' => true]));

        $this->getJson('/api/training-runs')->assertOk();
        $this->postJson('/api/training-runs', ['epochs' => 5])->assertStatus(403);
    }
}
