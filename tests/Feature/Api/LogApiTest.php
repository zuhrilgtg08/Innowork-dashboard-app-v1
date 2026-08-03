<?php

namespace Tests\Feature\Api;

use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LogApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    }

    public function test_it_lists_logs_newest_first(): void
    {
        $older = SystemLog::factory()->create(['logged_at' => now()->subHour()]);
        $newer = SystemLog::factory()->create(['logged_at' => now()]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/logs')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'level', 'level_color', 'source', 'message', 'logged_at']], 'meta']);

        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
    }

    public function test_it_filters_by_level_source_and_search(): void
    {
        SystemLog::factory()->create(['level' => 'error', 'source' => 'arm', 'message' => 'Gripper macet']);
        SystemLog::factory()->create(['level' => 'info', 'source' => 'camera', 'message' => 'Stream mulai']);

        $this->actingAsAdmin();

        $this->assertSame(1, $this->getJson('/api/logs?level=error')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/logs?source=camera')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/logs?search=Gripper')->assertOk()->json('meta.total'));
    }

    public function test_it_rejects_unknown_filter_values(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/logs?level=bogus')->assertStatus(422)->assertJsonValidationErrors(['level']);
        $this->getJson('/api/logs?source=bogus')->assertStatus(422)->assertJsonValidationErrors(['source']);
    }

    public function test_it_caps_per_page(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/logs?per_page=500')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    public function test_it_exposes_filter_options(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/logs/filters')
            ->assertOk()
            ->assertJsonStructure(['data' => ['levels' => [['key', 'color']], 'sources']]);
    }
}
