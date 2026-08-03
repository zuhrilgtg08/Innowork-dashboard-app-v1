<?php

namespace Tests\Feature\Api;

use App\Models\Detection;
use App\Models\ReturnBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReturnBatchApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSupervisor(): User
    {
        $user = User::factory()->create(['role' => 'supervisor_qc', 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_it_lists_batches_and_filters_by_status(): void
    {
        ReturnBatch::create(['conveyor' => 'CV-1', 'status' => 'open', 'reason' => 'defect']);
        ReturnBatch::create(['conveyor' => 'CV-2', 'status' => 'resolved', 'reason' => 'defect']);

        $this->actingAsSupervisor();

        $this->getJson('/api/returns')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'conveyor', 'status', 'status_label', 'detections_count']], 'meta']);

        $this->assertSame(1, $this->getJson('/api/returns?status=open')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/returns?conveyor=CV-2')->assertOk()->json('meta.total'));
    }

    public function test_it_shows_a_batch_with_its_detections(): void
    {
        $batch = ReturnBatch::create(['conveyor' => 'CV-1', 'status' => 'open', 'reason' => 'defect']);
        Detection::factory()->count(2)->create(['return_batch_id' => $batch->id, 'status' => 'damaged']);

        $this->actingAsSupervisor();

        $response = $this->getJson('/api/returns/'.$batch->id)
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'status', 'detections' => [['id', 'code', 'status', 'status_label']]]]);

        $this->assertCount(2, $response->json('data.detections'));
    }

    public function test_it_resolves_a_batch_and_attributes_it_to_the_caller(): void
    {
        $batch = ReturnBatch::create(['conveyor' => 'CV-1', 'status' => 'open', 'reason' => 'defect']);
        $user = $this->actingAsSupervisor();

        $this->postJson('/api/returns/'.$batch->id.'/resolve', ['notes' => 'Sudah disortir ulang.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $batch->refresh();
        $this->assertSame('resolved', $batch->status);
        $this->assertSame($user->id, $batch->resolved_by);
        $this->assertNotNull($batch->resolved_at);
        $this->assertSame('Sudah disortir ulang.', $batch->notes);
    }

    public function test_it_refuses_to_resolve_an_already_resolved_batch(): void
    {
        $batch = ReturnBatch::create(['conveyor' => 'CV-1', 'status' => 'resolved', 'reason' => 'defect']);
        $this->actingAsSupervisor();

        $this->postJson('/api/returns/'.$batch->id.'/resolve')->assertStatus(409);
    }

    public function test_a_viewer_cannot_resolve_a_batch(): void
    {
        $batch = ReturnBatch::create(['conveyor' => 'CV-1', 'status' => 'open', 'reason' => 'defect']);
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer', 'is_active' => true]));

        $this->postJson('/api/returns/'.$batch->id.'/resolve')->assertStatus(403);
        $this->assertSame('open', $batch->fresh()->status);
    }
}
