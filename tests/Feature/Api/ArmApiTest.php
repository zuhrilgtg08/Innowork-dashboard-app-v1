<?php

namespace Tests\Feature\Api;

use App\Models\ArmStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArmApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_default_idle_state(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/arm')
            ->assertOk()
            ->assertJsonPath('state', 'idle')
            ->assertJsonPath('state_label', 'Idle')
            ->assertJsonStructure(['state', 'state_label', 'detail', 'last_command', 'telemetry', 'reported_at']);
    }

    public function test_it_reflects_the_last_reported_state(): void
    {
        ArmStatus::current()->update([
            'state' => 'running',
            'detail' => 'Sorting batch A',
            'last_command' => 'start',
            'reported_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->create());

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
}
