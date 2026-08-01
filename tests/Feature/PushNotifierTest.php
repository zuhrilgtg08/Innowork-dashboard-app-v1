<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushNotifierTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'exp.host/*';

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

    private function device(User $user, string $token): DeviceToken
    {
        return DeviceToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'platform' => 'android',
        ]);
    }

    public function test_it_sends_to_every_device_of_the_given_users(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => []])]);

        $user = User::factory()->create();
        $this->device($user, 'ExponentPushToken[aaa]');
        $this->device($user, 'ExponentPushToken[bbb]');

        $sent = app(PushNotifier::class)->notifyUsers(collect([$user]), 'Judul', 'Isi');

        $this->assertSame(2, $sent);
        Http::assertSent(function (Request $request) {
            $tokens = array_column($request->data(), 'to');
            sort($tokens);

            return $tokens === ['ExponentPushToken[aaa]', 'ExponentPushToken[bbb]'];
        });
    }

    public function test_it_sends_nothing_when_there_are_no_devices(): void
    {
        Http::fake();

        $sent = app(PushNotifier::class)->notifyUsers(
            collect([User::factory()->create()]),
            'Judul',
            'Isi',
        );

        $this->assertSame(0, $sent);
        Http::assertNothingSent();
    }

    public function test_it_skips_tokens_that_are_not_expo_tokens(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => []])]);

        $user = User::factory()->create();
        // A malformed row must be dropped locally, not posted to Expo.
        $this->device($user, 'garbage-token');

        $this->assertSame(0, app(PushNotifier::class)->notifyUsers(collect([$user]), 'A', 'B'));
        Http::assertNothingSent();
    }

    public function test_it_notifies_only_roles_that_can_read_the_module(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => []])]);
        $this->seedRolePermissions();

        // viewer has '-' on Training in the default matrix; admin has 'f'.
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->device($admin, 'ExponentPushToken[admin]');
        $this->device($viewer, 'ExponentPushToken[viewer]');

        app(PushNotifier::class)->notifyModuleWatchers('Training', 'Judul', 'Isi');

        Http::assertSent(function (Request $request) {
            return array_column($request->data(), 'to') === ['ExponentPushToken[admin]'];
        });
    }

    public function test_it_does_not_notify_deactivated_accounts(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => []])]);
        $this->seedRolePermissions();

        $user = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $this->device($user, 'ExponentPushToken[inactive]');

        $this->assertSame(0, app(PushNotifier::class)->notifyModuleWatchers('Training', 'A', 'B'));
        Http::assertNothingSent();
    }

    public function test_it_deletes_tokens_expo_reports_as_unregistered(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['data' => [
                ['status' => 'ok'],
                ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            ]]),
        ]);

        $user = User::factory()->create();
        $this->device($user, 'ExponentPushToken[alive]');
        $this->device($user, 'ExponentPushToken[dead]');

        app(PushNotifier::class)->notifyUsers(collect([$user]), 'A', 'B');

        $this->assertDatabaseHas('device_tokens', ['token' => 'ExponentPushToken[alive]']);
        $this->assertDatabaseMissing('device_tokens', ['token' => 'ExponentPushToken[dead]']);
    }

    public function test_a_transport_failure_does_not_throw(): void
    {
        // A push outage must never turn a successful QC event into a 500.
        Http::fake([self::ENDPOINT => Http::response('boom', 500)]);

        $user = User::factory()->create();
        $this->device($user, 'ExponentPushToken[aaa]');

        $this->assertSame(0, app(PushNotifier::class)->notifyUsers(collect([$user]), 'A', 'B'));
    }

    public function test_one_handset_shared_by_two_operators_is_buzzed_once(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['data' => []])]);

        // The token is unique per device, so when a second operator signs in on
        // the same handset the row moves to them (see DeviceTokenController).
        // Notifying both accounts must still produce a single message.
        $previous = User::factory()->create();
        $current = User::factory()->create();
        $this->device($current, 'ExponentPushToken[shared]');

        $sent = app(PushNotifier::class)->notifyUsers(collect([$previous, $current]), 'A', 'B');

        $this->assertSame(1, $sent);
        Http::assertSent(fn (Request $request) => count($request->data()) === 1);
    }
}
