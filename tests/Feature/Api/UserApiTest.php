<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    /** Two admins, so the "last admin" guard is not tripped by default. */
    private function actingAsAdmin(): User
    {
        User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_it_lists_and_filters_users(): void
    {
        $this->actingAsAdmin();
        User::factory()->create(['name' => 'Budi Operator', 'role' => 'operator']);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'role_label', 'is_active']], 'meta']);

        $this->assertSame(1, $this->getJson('/api/users?role=operator')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/users?search=Budi')->assertOk()->json('meta.total'));
    }

    public function test_it_never_exposes_the_password_hash(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/users')->assertOk();

        $this->assertArrayNotHasKey('password', $response->json('data.0'));
    }

    public function test_it_creates_a_user_with_a_hashed_password(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/users', [
            'name' => 'Siti',
            'email' => 'siti@sortvision.test',
            'role' => 'operator',
            'password' => 'rahasia123',
        ])->assertCreated();

        $created = User::where('email', 'siti@sortvision.test')->first();
        $this->assertNotSame('rahasia123', $created->password);
        $this->assertTrue(Hash::check('rahasia123', $created->password));
    }

    public function test_it_requires_a_password_on_create_but_not_on_update(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/users', [
            'name' => 'X', 'email' => 'x@sortvision.test', 'role' => 'viewer',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $user = User::factory()->create(['role' => 'viewer']);
        $originalHash = $user->password;

        $this->putJson('/api/users/'.$user->id, [
            'name' => 'X2', 'email' => 'x2@sortvision.test', 'role' => 'viewer',
        ])->assertOk();

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_it_rejects_a_duplicate_email_but_allows_keeping_your_own(): void
    {
        $this->actingAsAdmin();
        $taken = User::factory()->create(['email' => 'taken@sortvision.test']);
        $other = User::factory()->create(['email' => 'other@sortvision.test', 'role' => 'viewer']);

        $this->putJson('/api/users/'.$other->id, [
            'name' => 'O', 'email' => 'taken@sortvision.test', 'role' => 'viewer',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);

        // Keeping the same address must not trip the unique rule.
        $this->putJson('/api/users/'.$taken->id, [
            'name' => 'T', 'email' => 'taken@sortvision.test', 'role' => $taken->role,
        ])->assertOk();
    }

    public function test_the_last_admin_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $soleAdmin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($soleAdmin);

        $this->putJson('/api/users/'.$soleAdmin->id, [
            'name' => $soleAdmin->name, 'email' => $soleAdmin->email, 'role' => 'viewer',
        ])->assertStatus(422)->assertJsonValidationErrors(['role']);

        $this->putJson('/api/users/'.$soleAdmin->id, [
            'name' => $soleAdmin->name, 'email' => $soleAdmin->email, 'role' => 'admin', 'is_active' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['is_active']);

        $this->deleteJson('/api/users/'.$soleAdmin->id)->assertStatus(422);

        $this->assertSame('admin', $soleAdmin->fresh()->role);
        $this->assertDatabaseHas('users', ['id' => $soleAdmin->id]);
    }

    public function test_a_user_cannot_delete_their_own_account(): void
    {
        $admin = $this->actingAsAdmin();

        $this->deleteJson('/api/users/'.$admin->id)->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_it_deletes_another_user(): void
    {
        $this->actingAsAdmin();
        $victim = User::factory()->create(['role' => 'viewer']);

        $this->deleteJson('/api/users/'.$victim->id)->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $victim->id]);
    }
}
