<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
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

    public function test_admin_can_create_employee_and_agency_owner_and_send_invites(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $employeeResponse = $this->actingAs($admin)->postJson('/api/auth/users', [
            'name' => 'New Employee',
            'email' => 'EMPLOYEE@example.com',
            'role' => Role::Employee->value,
        ]);
        $employeeResponse->assertCreated()
            ->assertJsonPath('user.role', Role::Employee->value)
            ->assertJsonPath('user.agency_id', null);
        $employee = User::where('email', 'employee@example.com')->firstOrFail();
        $this->assertTrue($employee->is_active);
        Notification::assertSentTo($employee, SetPasswordNotification::class);

        $ownerResponse = $this->postJson('/api/auth/users', [
            'name' => 'Agency Owner',
            'email' => 'owner@example.com',
            'role' => Role::AgencyOwner->value,
            'agency_name' => 'Northwind',
        ]);
        $agency = Agency::where('name', 'Northwind')->firstOrFail();
        $ownerResponse->assertCreated()
            ->assertJsonPath('user.role', Role::AgencyOwner->value)
            ->assertJsonPath('user.agency_id', $agency->id)
            ->assertJsonPath('user.agency_name', 'Northwind');
        Notification::assertSentTo(User::where('email', 'owner@example.com')->firstOrFail(), SetPasswordNotification::class);
    }

    public function test_admin_creation_rejects_unsupported_roles_and_invalid_agency_ids(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $agency = Agency::create(['name' => 'Northwind']);

        $this->actingAs($admin)->postJson('/api/auth/users', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'role' => Role::AgencyOwner->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['agency_name']);

        $this->postJson('/api/auth/users', [
            'name' => 'Owner',
            'email' => 'another-owner@example.com',
            'role' => Role::AgencyOwner->value,
            'agency_name' => 'Created automatically',
            'agency_id' => $agency->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['agency_id']);

        $this->postJson('/api/auth/users', [
            'name' => 'Client',
            'email' => 'client@example.com',
            'role' => Role::AgencyClient->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);

        $this->postJson('/api/auth/users', [
            'name' => 'Team lead',
            'email' => 'team-lead@example.com',
            'role' => Role::TeamLead->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);

        $this->postJson('/api/auth/users', [
            'name' => 'Agency member',
            'email' => 'member@example.com',
            'role' => Role::AgencyMember->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);

        $this->postJson('/api/auth/users', [
            'name' => 'Employee',
            'email' => 'employee@example.com',
            'role' => Role::Employee->value,
            'agency_id' => $agency->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['agency_id']);

        $this->postJson('/api/auth/users', [
            'name' => 'Agency employee',
            'email' => 'agency-employee@example.com',
            'role' => 'agency_employee',
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);

        $this->postJson('/api/auth/users', [
            'name' => 'Agency owner',
            'email' => 'agency-owner@example.com',
            'role' => Role::AgencyOwner->value,
            'agency_name' => 'Northwind',
            'agency_id' => $agency->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['agency_id']);
    }

    public function test_agency_owner_creates_agency_users_in_own_agency_and_cannot_override_agency(): void
    {
        Notification::fake();
        $agency = Agency::create(['name' => 'Owner Agency']);
        $otherAgency = Agency::create(['name' => 'Other Agency']);
        $owner = User::factory()->create([
            'role' => Role::AgencyOwner,
            'agency_id' => $agency->id,
        ]);

        $response = $this->actingAs($owner)->postJson('/api/agency/users', [
            'name' => 'Invited Client',
            'email' => 'CLIENT@example.com',
            'role' => Role::AgencyClient->value,
            'agency_id' => $otherAgency->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', Role::AgencyClient->value)
            ->assertJsonPath('user.agency_id', $agency->id)
            ->assertJsonPath('user.agency_name', 'Owner Agency');

        $client = User::where('email', 'client@example.com')->firstOrFail();
        $this->assertSame(Role::AgencyClient, $client->role);
        $this->assertSame($agency->id, $client->agency_id);
        Notification::assertSentTo($client, SetPasswordNotification::class);
    }

    public function test_agency_owner_cannot_invite_without_an_active_agency(): void
    {
        $agency = Agency::create(['name' => 'Suspended Agency', 'status' => 'suspended']);
        $owner = User::factory()->create([
            'role' => Role::AgencyOwner,
            'agency_id' => $agency->id,
        ]);

        $this->actingAs($owner)->postJson('/api/agency/users', [
            'name' => 'Member',
            'email' => 'member@example.com',
            'role' => Role::AgencyClient->value,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'member@example.com']);
    }

    public function test_guests_receive_401_on_account_creation_endpoints(): void
    {
        $this->postJson('/api/auth/users', [])->assertUnauthorized();
        $this->postJson('/api/agency/users', [])->assertUnauthorized();
    }

    public function test_non_admin_roles_receive_403_on_both_account_creation_endpoints(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);

        foreach ([Role::AgencyMember, Role::Employee, Role::Qa, Role::TeamLead] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'agency_id' => $role->isAgencyRole() ? $agency->id : null,
            ]);

            $this->actingAs($user)->postJson('/api/auth/users', [
                'name' => 'New User',
                'email' => 'admin-route-'.$role->value.'@example.com',
                'role' => Role::Employee->value,
            ])->assertForbidden();

            $this->postJson('/api/agency/users', [
                'name' => 'New Member',
                'email' => 'agency-route-'.$role->value.'@example.com',
            ])->assertForbidden();
        }
    }

    public function test_validation_errors_include_field_details_and_public_signup_is_disabled(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->postJson('/api/auth/users', [
            'name' => '',
            'email' => 'not-an-email',
            'role' => 'unknown',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonValidationErrors(['name', 'email', 'role']);

        $this->postJson('/api/auth/signup')->assertNotFound();
        $this->postJson('/api/auth/register')->assertNotFound();
    }

    public function test_login_and_logout_use_a_server_side_session_and_me_returns_agency_details(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'GoodPassword123!',
            'role' => Role::AgencyOwner,
            'agency_id' => $agency->id,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'GoodPassword123!',
        ])->assertOk()->assertJsonPath('user.role', Role::AgencyOwner->value);

        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', Role::AgencyOwner->value)
            ->assertJsonPath('user.agency_id', $agency->id)
            ->assertJsonPath('user.agency_name', 'Northwind');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_login_uses_a_generic_error_for_invalid_inactive_and_suspended_users(): void
    {
        $active = User::factory()->create([
            'email' => 'known@example.com',
            'password' => 'GoodPassword123!',
        ]);

        $inactive = User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => 'GoodPassword123!',
            'is_active' => false,
        ]);
        $agency = Agency::create(['name' => 'Suspended', 'status' => 'suspended']);
        $suspended = User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => 'GoodPassword123!',
            'agency_id' => $agency->id,
        ]);

        foreach ([$active, $inactive, $suspended] as $user) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => $user->is($active) ? 'incorrect' : 'GoodPassword123!',
            ])->assertUnprocessable()
                ->assertJsonPath('message', 'The provided credentials are incorrect.');
            $this->assertGuest();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'incorrect',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    public function test_forgot_password_does_not_disclose_email_existence_and_sends_custom_notification(): void
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

        Notification::assertSentTo($user, SetPasswordNotification::class);
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

    public function test_role_middleware_blocks_users_without_the_required_role(): void
    {
        Route::get('/test/admin-only', fn () => response()->json(['ok' => true]))
            ->middleware(['auth', 'role:admin,team_lead']);

        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($employee)->getJson('/test/admin-only')->assertForbidden();

        $teamLead = User::factory()->create(['role' => Role::TeamLead]);
        $this->actingAs($teamLead)->getJson('/test/admin-only')->assertOk();
    }
}
