<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers `App\Http\Middleware\EnsureModuleAccess` — the role × module
 * matrix enforcement that the mobile API applies (and the web dashboard does
 * not).
 */
class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_viewer_can_read_products_but_not_write(): void
    {
        // Baseline matrix grants viewer 'r' on Product.
        $product = Product::factory()->create();
        $this->actingAsRole('viewer');

        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/products/'.$product->id)->assertOk();

        $this->postJson('/api/products', [
            'name' => 'Baru', 'status' => 'active', 'stock' => 1,
        ])->assertStatus(403);

        $this->deleteJson('/api/products/'.$product->id)->assertStatus(403);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_operator_cannot_touch_users_module_at_all(): void
    {
        // Baseline matrix gives operator '-' on Users.
        $this->actingAsRole('operator');

        $this->getJson('/api/users')->assertStatus(403);
        $this->postJson('/api/users', [])->assertStatus(403);
    }

    public function test_operator_can_write_returns(): void
    {
        // Baseline matrix gives operator 'w' on Returns.
        $this->actingAsRole('operator');

        $this->getJson('/api/returns')->assertOk();
    }

    public function test_admin_has_full_access(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/settings')->assertOk();
        $this->getJson('/api/logs')->assertOk();
    }

    public function test_viewer_cannot_read_settings(): void
    {
        // Baseline matrix gives viewer '-' on Settings.
        $this->actingAsRole('viewer');

        $this->getJson('/api/settings')->assertStatus(403);
    }

    public function test_a_stored_override_beats_the_default_matrix(): void
    {
        RolePermission::updateOrCreate(
            ['role' => 'viewer', 'module' => 'Settings'],
            ['access' => 'r'],
        );

        $this->actingAsRole('viewer');

        $this->getJson('/api/settings')->assertOk();
        // Read-only override still must not permit writes.
        $this->putJson('/api/settings', ['app_name' => 'Nope'])->assertStatus(403);
    }

    public function test_a_deactivated_user_is_locked_out(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        Sanctum::actingAs($user);

        $this->getJson('/api/products')->assertStatus(403);
    }

    public function test_the_403_body_names_the_module_and_required_level(): void
    {
        $this->actingAsRole('viewer');

        $this->postJson('/api/products', ['name' => 'x', 'status' => 'active', 'stock' => 0])
            ->assertStatus(403)
            ->assertJsonPath('module', 'Product')
            ->assertJsonPath('required', 'write');
    }
}
