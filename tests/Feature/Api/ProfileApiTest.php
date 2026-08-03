<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_the_profile(): void
    {
        $this->getJson('/api/profile')->assertStatus(401);
    }

    public function test_it_returns_the_signed_in_user(): void
    {
        $user = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.role', 'operator');
    }

    public function test_it_updates_name_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Lama', 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', [
            'name' => 'Nama Baru',
            'email' => 'baru@sortvision.test',
        ])->assertOk()->assertJsonPath('user.name', 'Nama Baru');

        $this->assertSame('baru@sortvision.test', $user->fresh()->email);
    }

    public function test_changing_the_email_clears_verification(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', [
            'name' => $user->name,
            'email' => 'pindah@sortvision.test',
        ])->assertOk();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_keeping_the_same_email_is_not_a_uniqueness_conflict(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['name' => 'Tetap', 'email' => $user->email])
            ->assertOk();

        $this->assertSame('Tetap', $user->fresh()->name);
    }

    public function test_changing_the_password_revokes_other_sessions_but_not_this_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password-lama'), 'is_active' => true]);

        // A second device that must be kicked out when the password changes.
        $otherDevice = $user->createToken('tablet');
        // Sanctum::actingAs() fakes the token, so use a real one for this test:
        // the controller compares token ids and a fake has none.
        $thisDevice = $user->createToken('phone');

        $this->withHeader('Authorization', 'Bearer '.$thisDevice->plainTextToken)
            ->putJson('/api/profile/password', [
                'current_password' => 'password-lama',
                'password' => 'password-baru',
                'password_confirmation' => 'password-baru',
            ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherDevice->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $thisDevice->accessToken->id]);
    }

    public function test_it_rejects_an_email_already_taken_by_someone_else(): void
    {
        $other = User::factory()->create(['email' => 'dipakai@sortvision.test']);
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['name' => 'X', 'email' => $other->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_a_role_without_users_module_can_still_edit_itself(): void
    {
        // viewer has no Users access in the matrix; the profile route must not
        // be gated on that module.
        $user = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile', ['name' => 'Viewer Baru', 'email' => $user->email])
            ->assertOk();
    }

    public function test_it_changes_the_password_with_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password-lama'), 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/password', [
            'current_password' => 'password-lama',
            'password' => 'password-baru',
            'password_confirmation' => 'password-baru',
        ])->assertOk();

        $this->assertTrue(Hash::check('password-baru', $user->fresh()->password));
    }

    public function test_it_rejects_a_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password-lama'), 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/password', [
            'current_password' => 'salah',
            'password' => 'password-baru',
            'password_confirmation' => 'password-baru',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password-lama', $user->fresh()->password));
    }

    public function test_it_requires_the_new_password_to_be_confirmed(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password-lama'), 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/profile/password', [
            'current_password' => 'password-lama',
            'password' => 'password-baru',
            'password_confirmation' => 'beda',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
