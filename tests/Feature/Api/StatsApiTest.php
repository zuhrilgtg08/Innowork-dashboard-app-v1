<?php

namespace Tests\Feature\Api;

use App\Models\Detection;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StatsApiTest extends TestCase
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

    public function test_guest_cannot_read_dashboard_stats(): void
    {
        $this->getJson('/api/stats/dashboard')->assertStatus(401);
    }

    public function test_it_returns_zeroed_stats_when_there_are_no_detections(): void
    {
        $this->actingAsRole();

        $this->getJson('/api/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('range', 'today')
            ->assertJsonPath('stats.total', 0)
            // Division by zero would be the easy bug here.
            ->assertJsonPath('stats.pass_rate', 0)
            ->assertJsonPath('distribution.0.pct', 0);
    }

    public function test_it_counts_todays_detections_by_status(): void
    {
        $this->actingAsRole();

        Detection::factory()->count(3)->create(['status' => 'passed', 'detected_at' => now()]);
        Detection::factory()->create(['status' => 'damaged', 'detected_at' => now()]);
        // Yesterday: must not leak into the "today" range.
        Detection::factory()->create(['status' => 'passed', 'detected_at' => now()->subDay()]);

        $response = $this->getJson('/api/stats/dashboard')->assertOk();

        $response->assertJsonPath('stats.total', 4)
            ->assertJsonPath('stats.passed', 3)
            ->assertJsonPath('stats.defective', 1)
            ->assertJsonPath('stats.pass_rate', 75); // JSON tidak membedakan 75 dan 75.0
    }

    public function test_the_range_parameter_widens_the_window(): void
    {
        $this->actingAsRole();

        Detection::factory()->create(['status' => 'passed', 'detected_at' => now()]);
        Detection::factory()->create(['status' => 'passed', 'detected_at' => now()->subDays(3)]);

        $this->getJson('/api/stats/dashboard?range=today')->assertJsonPath('stats.total', 1);
        $this->getJson('/api/stats/dashboard?range=7d')
            ->assertJsonPath('range', '7d')
            ->assertJsonPath('stats.total', 2);
    }

    public function test_it_rejects_an_unknown_range(): void
    {
        $this->actingAsRole();

        $this->getJson('/api/stats/dashboard?range=all-time')
            ->assertStatus(422)
            ->assertJsonValidationErrors('range');
    }

    public function test_distribution_covers_every_status_even_at_zero(): void
    {
        $this->actingAsRole();

        Detection::factory()->create(['status' => 'passed', 'detected_at' => now()]);

        $distribution = $this->getJson('/api/stats/dashboard')->json('distribution');

        $this->assertCount(count(Detection::STATUSES), $distribution);
        $this->assertSame(
            array_keys(Detection::STATUSES),
            array_column($distribution, 'key'),
        );
    }

    public function test_trend_returns_an_even_time_axis(): void
    {
        $this->actingAsRole();

        Detection::factory()->create(['status' => 'passed', 'detected_at' => now()]);

        // 24 hourly buckets for today, 7 daily buckets for the week.
        $this->assertCount(24, $this->getJson('/api/stats/dashboard')->json('trend'));
        $this->assertCount(7, $this->getJson('/api/stats/dashboard?range=7d')->json('trend'));
    }

    public function test_a_role_without_dashboard_access_is_denied(): void
    {
        $user = $this->actingAsRole();
        RolePermission::where('role', $user->role)->where('module', 'Dashboard')->update(['access' => '-']);

        $this->getJson('/api/stats/dashboard')->assertStatus(403);
    }
}
