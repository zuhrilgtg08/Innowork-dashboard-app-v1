<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Public registration is disabled for the demo: accounts are managed by
 * administrators (Users page). /register redirects to /login for every
 * method and never creates a user.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_redirects_to_login(): void
    {
        $this->get('/register')->assertRedirect('/login');
    }

    public function test_register_post_redirects_to_login_without_creating_users(): void
    {
        $response = $this->withoutMiddleware(
            VerifyCsrfToken::class
        )->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
