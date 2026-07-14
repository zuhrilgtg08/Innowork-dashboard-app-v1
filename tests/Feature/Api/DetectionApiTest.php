<?php

namespace Tests\Feature\Api;

use App\Models\Detection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DetectionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_recent_detections_newest_first(): void
    {
        $older = Detection::factory()->create(['detected_at' => now()->subDay()]);
        $newer = Detection::factory()->create(['detected_at' => now()]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/detections')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'code', 'status', 'status_label', 'camera', 'conveyor', 'confidence', 'qr_value', 'detected_at']],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        // Newest detection comes first.
        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_it_can_filter_by_status(): void
    {
        Detection::factory()->create(['status' => 'passed']);
        Detection::factory()->create(['status' => 'damaged']);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/detections?status=damaged')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('damaged', $response->json('data.0.status'));
    }

    public function test_it_rejects_an_unknown_status_filter(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/detections?status=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_it_respects_per_page(): void
    {
        Detection::factory()->count(5)->create();

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/detections?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame(5, $response->json('meta.total'));
    }

    public function test_guest_cannot_access_detections(): void
    {
        $this->getJson('/api/detections')->assertStatus(401);
    }
}
