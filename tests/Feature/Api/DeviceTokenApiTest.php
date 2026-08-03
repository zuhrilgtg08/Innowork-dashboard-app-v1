<?php

namespace Tests\Feature\Api;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceTokenApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'ExponentPushToken[abcdef1234567890]';

    public function test_guest_cannot_register_a_device(): void
    {
        $this->postJson('/api/device-tokens', ['token' => self::TOKEN])->assertStatus(401);
    }

    public function test_it_registers_a_device_for_the_signed_in_user(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/device-tokens', ['token' => self::TOKEN, 'platform' => 'android'])
            ->assertStatus(201);

        $this->assertDatabaseHas('device_tokens', [
            'token' => self::TOKEN,
            'user_id' => $user->id,
            'platform' => 'android',
        ]);
    }

    public function test_registering_twice_does_not_duplicate_the_device(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/device-tokens', ['token' => self::TOKEN])->assertStatus(201);
        $this->postJson('/api/device-tokens', ['token' => self::TOKEN])->assertStatus(201);

        $this->assertSame(1, DeviceToken::where('token', self::TOKEN)->count());
    }

    public function test_a_second_operator_on_the_same_handset_takes_over_the_token(): void
    {
        $first = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($first);
        $this->postJson('/api/device-tokens', ['token' => self::TOKEN])->assertStatus(201);

        // Same physical device, different account signing in — Expo hands out
        // the same token, and the alert must follow whoever is signed in now.
        $second = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($second);
        $this->postJson('/api/device-tokens', ['token' => self::TOKEN])->assertStatus(201);

        $this->assertSame(1, DeviceToken::where('token', self::TOKEN)->count());
        $this->assertDatabaseHas('device_tokens', [
            'token' => self::TOKEN,
            'user_id' => $second->id,
        ]);
    }

    public function test_it_rejects_a_token_that_is_not_an_expo_token(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->postJson('/api/device-tokens', ['token' => 'not-a-real-token'])
            ->assertStatus(422);

        $this->assertDatabaseCount('device_tokens', 0);
    }

    public function test_it_rejects_an_unknown_platform(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->postJson('/api/device-tokens', ['token' => self::TOKEN, 'platform' => 'blackberry'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');
    }

    public function test_it_unregisters_a_device_on_logout(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/device-tokens', ['token' => self::TOKEN])->assertStatus(201);
        $this->deleteJson('/api/device-tokens', ['token' => self::TOKEN])->assertOk();

        $this->assertDatabaseCount('device_tokens', 0);
    }

    public function test_one_user_cannot_unregister_another_users_device(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($owner);
        $this->postJson('/api/device-tokens', ['token' => self::TOKEN])->assertStatus(201);

        // Knowing the token string must not be enough to silence someone else.
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));
        $this->deleteJson('/api/device-tokens', ['token' => self::TOKEN])->assertOk();

        $this->assertDatabaseHas('device_tokens', [
            'token' => self::TOKEN,
            'user_id' => $owner->id,
        ]);
    }

    public function test_deleting_a_user_removes_their_devices(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);
        $this->postJson('/api/device-tokens', ['token' => self::TOKEN])->assertStatus(201);

        $user->delete();

        $this->assertDatabaseCount('device_tokens', 0);
    }
}
