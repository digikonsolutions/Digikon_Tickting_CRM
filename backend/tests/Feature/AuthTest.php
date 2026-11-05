<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_cookie_endpoint_supports_the_configured_spa_origin(): void
    {
        $response = $this->get('/api/auth/csrf-cookie', [
            'Origin' => 'http://localhost:5173',
        ]);

        $response->assertNoContent()
            ->assertCookie('XSRF-TOKEN');

        $this->assertSame('http://localhost:5173', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_signup_is_admin_only_and_creates_the_requested_role(): void
    {
        $data = [
            'name' => 'New Client',
            'email' => 'OWNER@example.com',
            'password' => 'GoodPassword123!',
            'password_confirmation' => 'GoodPassword123!',
            'role' => UserRole::AgencyOwner->value,
        ];

        $this->postJson('/api/auth/users', $data)->assertUnauthorized();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $response = $this->actingAs($admin)->postJson('/api/auth/users', $data);

        $response->assertCreated()
            ->assertJsonPath('user.role', UserRole::AgencyOwner->value)
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('users', [
            'email' => 'owner@example.com',
            'role' => UserRole::AgencyOwner->value,
        ]);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_non_admin_users_cannot_create_accounts(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->postJson('/api/auth/users', [
            'name' => 'Another User',
            'email' => 'another@example.com',
            'password' => 'GoodPassword123!',
            'password_confirmation' => 'GoodPassword123!',
            'role' => UserRole::Client->value,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'another@example.com']);
    }

    public function test_api_validation_errors_include_field_details(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();

        $this->actingAs($admin)->postJson('/api/auth/users', [
            'name' => '',
            'email' => 'not-an-email',
            'role' => 'unknown',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonValidationErrors(['name', 'email', 'role', 'password']);
    }

    public function test_api_auth_and_not_found_errors_are_json(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->getJson('/api/not-a-route')
            ->assertNotFound()
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    public function test_api_unexpected_errors_do_not_expose_internal_details(): void
    {
        Route::get('/api/test-error', function () {
            throw new \RuntimeException('Sensitive internal exception details.');
        });

        $this->getJson('/api/test-error')
            ->assertInternalServerError()
            ->assertJsonPath('message', 'The server encountered an error. Please try again.')
            ->assertDontSee('Sensitive internal exception details.');
    }

    public function test_public_signup_routes_are_not_available(): void
    {
        $this->postJson('/api/auth/signup')->assertNotFound();
        $this->postJson('/api/auth/register')->assertNotFound();
    }

    public function test_login_and_logout_use_a_server_side_session(): void
    {
        $user = User::factory()->create(['password' => 'GoodPassword123!']);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'GoodPassword123!',
        ])->assertOk()->assertJsonPath('user.role', UserRole::Client->value);

        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_login_uses_a_generic_error_for_invalid_credentials(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'known@example.com',
            'password' => 'incorrect',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are incorrect.');

        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'incorrect',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    public function test_forgot_password_does_not_disclose_whether_an_email_exists(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'unknown@example.com',
        ])->assertAccepted()
            ->assertJsonPath('message', 'If an account exists for that email, password reset instructions will be sent.');

        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ])->assertAccepted()
            ->assertJsonPath('message', 'If an account exists for that email, password reset instructions will be sent.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_changes_the_password_and_revokes_sessions(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        DB::table('sessions')->insert([
            'id' => 'existing-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->assertTrue(password_verify('NewPassword123!', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'existing-session']);
    }

    public function test_role_middleware_blocks_users_without_the_required_role(): void
    {
        Route::get('/test/admin-only', fn () => response()->json(['ok' => true]))
            ->middleware(['auth', 'role:admin']);

        $client = User::factory()->create();
        $this->actingAs($client)->getJson('/test/admin-only')->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($admin)->getJson('/test/admin-only')->assertOk();
    }
}
