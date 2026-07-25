<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_a_token_and_user(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@sortvision.test',
            'password' => Hash::make('password'),
            'role' => 'operator',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'operator@sortvision.test',
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']])
            ->assertJsonPath('user.email', 'operator@sortvision.test')
            ->assertJsonPath('user.role', 'operator');

        $this->assertNotEmpty($response->json('token'));
        $this->assertCount(1, $user->fresh()->tokens);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_login_with_missing_fields_returns_422(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'not-an-email',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => 'supervisor_qc']);

        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'supervisor_qc');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_guest_cannot_access_me(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_guest_cannot_logout(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(401);
    }
}
