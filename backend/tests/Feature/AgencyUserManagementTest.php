<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AgencyUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_clients_in_their_agency_and_shared_employees(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $otherAgency = Agency::create(['name' => 'Other Agency']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee, 'agency_id' => null]);
        User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]);

        $this->actingAs($owner)
            ->getJson('/api/agency/users')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $employee->id)
            ->assertJsonPath('users.0.role', Role::Employee->value)
            ->assertJsonPath('users.0.is_active', true);
    }

    public function test_owner_can_invite_clients_but_cannot_invite_employees(): void
    {
        Notification::fake();
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);

        $this->actingAs($owner)
            ->postJson('/api/agency/users', [
                'name' => 'New employee',
                'email' => 'employee@example.com',
                'role' => 'agency_employee',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'employee@example.com']);

        $this->postJson('/api/agency/users', [
            'name' => 'New client',
            'email' => 'client@example.com',
            'role' => Role::AgencyClient->value,
        ])->assertCreated()
            ->assertJsonPath('user.role', Role::AgencyClient->value)
            ->assertJsonPath('user.agency_id', $agency->id);

        $this->postJson('/api/agency/users', [
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'role' => Role::Admin->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_owner_cannot_edit_or_deactivate_an_employee(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $employee = User::factory()->create([
            'role' => Role::Employee,
            'agency_id' => null,
        ]);
        DB::table('sessions')->insert([
            'id' => 'employee-session',
            'user_id' => $employee->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->actingAs($owner)
            ->patchJson('/api/agency/users/'.$employee->id, [
                'name' => 'Updated name',
                'is_active' => false,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $employee->id, 'name' => $employee->name, 'is_active' => true]);
        $this->assertDatabaseHas('sessions', ['id' => 'employee-session']);
        $this->deleteJson('/api/agency/users/'.$employee->id)->assertNotFound();
    }

    public function test_email_change_sends_password_setup_email_and_revokes_existing_sessions(): void
    {
        Notification::fake();
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create([
            'role' => Role::AgencyClient,
            'agency_id' => $agency->id,
        ]);
        DB::table('sessions')->insert([
            'id' => 'client-session',
            'user_id' => $client->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->actingAs($owner)
            ->patchJson('/api/agency/users/'.$client->id, [
                'email' => 'NEW-CLIENT@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('user.email', 'new-client@example.com')
            ->assertJsonPath('user.password_setup_email_sent', true);

        $client->refresh();
        $this->assertSame('new-client@example.com', $client->email);
        $this->assertDatabaseMissing('sessions', ['id' => 'client-session']);
        Notification::assertSentTo($client, SetPasswordNotification::class);
    }

    public function test_owner_can_permanently_delete_only_managed_users_in_their_agency(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $otherAgency = Agency::create(['name' => 'Other Agency']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $otherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]);
        DB::table('sessions')->insert([
            'id' => 'client-session',
            'user_id' => $client->id,
            'payload' => '',
            'last_activity' => time(),
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => $client->email,
            'token' => 'reset-token',
            'created_at' => now(),
        ]);

        $this->actingAs($owner)
            ->deleteJson('/api/agency/users/'.$client->id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $client->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'client-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $client->email]);

        $this->deleteJson('/api/agency/users/'.$otherClient->id)->assertNotFound();
        $this->deleteJson('/api/agency/users/'.$owner->id)->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $otherClient->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_only_agency_owners_can_manage_agency_users(): void
    {
        $this->getJson('/api/agency/users')->assertUnauthorized();
        $this->postJson('/api/agency/users', [])->assertUnauthorized();

        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($client)->getJson('/api/agency/users')->assertForbidden();
        $this->postJson('/api/agency/users', [])->assertForbidden();

        $this->actingAs($employee)->getJson('/api/agency/users')->assertForbidden();
        $this->postJson('/api/agency/users', [
            'name' => 'New client',
            'email' => 'new.client@example.com',
            'role' => Role::AgencyClient->value,
        ])->assertForbidden();
        $this->patchJson('/api/agency/users/'.$client->id, ['name' => 'Updated client'])->assertForbidden();
        $this->deleteJson('/api/agency/users/'.$client->id)->assertForbidden();
        $this->getJson('/api/agency/directory')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new.client@example.com']);
        $this->assertDatabaseHas('users', ['id' => $client->id]);
    }

    public function test_agency_owner_can_see_but_not_manage_admin_created_employees(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $globalEmployee = User::factory()->create(['role' => Role::Employee, 'agency_id' => null]);

        $this->actingAs($owner)
            ->getJson('/api/agency/users')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $globalEmployee->id,
                'role' => Role::Employee->value,
                'can_manage' => false,
            ]);

        $this->patchJson('/api/agency/users/'.$globalEmployee->id, ['name' => 'Changed'])
            ->assertNotFound();
        $this->deleteJson('/api/agency/users/'.$globalEmployee->id)->assertNotFound();
    }

    public function test_admin_user_page_lists_users_with_agency_and_status_and_search(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $agency = Agency::create(['name' => 'Northwind']);
        $member = User::factory()->create([
            'name' => 'Internal Employee',
            'email' => 'internal.employee@example.com',
            'role' => Role::Employee,
        ]);
        User::factory()->create([
            'name' => 'Agency Client',
            'email' => 'agency.client@example.com',
            'role' => Role::AgencyClient,
            'agency_id' => $agency->id,
        ]);
        User::factory()->create([
            'name' => 'Agency Owner',
            'email' => 'agency.owner@example.com',
            'role' => Role::AgencyOwner,
            'agency_id' => $agency->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/users?role=employee&search=internal.employee')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.id', $member->id)
            ->assertJsonPath('users.0.agency_name', null)
            ->assertJsonPath('users.0.is_active', true)
            ->assertJsonPath('pagination.total', 1);

        $this->getJson('/api/admin/users?role=agency_client')
            ->assertOk()
            ->assertJsonPath('users.0.role', Role::AgencyClient->value)
            ->assertJsonPath('pagination.total', 1);

        $this->getJson('/api/admin/users?role=agency_owner')
            ->assertOk()
            ->assertJsonPath('users.0.role', Role::AgencyOwner->value)
            ->assertJsonPath('pagination.total', 1);

        $this->getJson('/api/admin/users?role=employee&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/admin/users?role=agency_employee')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_admin_can_view_all_roles_and_manage_every_employee_and_agency_owner(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $owner = User::factory()->create(['role' => Role::AgencyOwner]);
        $client = User::factory()->create(['role' => Role::AgencyClient]);
        $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonMissing(['id' => $admin->id])
            ->assertJsonFragment(['id' => $employee->id, 'can_manage' => true])
            ->assertJsonFragment(['id' => $owner->id, 'can_manage' => true])
            ->assertJsonFragment(['id' => $client->id, 'can_manage' => false]);

        $this->patchJson('/api/admin/users/'.$employee->id, ['name' => 'Edited employee'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Edited employee');
        $this->patchJson('/api/admin/users/'.$employee->id, ['role' => Role::Admin->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
        $this->patchJson('/api/admin/users/'.$client->id, ['name' => 'Not allowed'])
            ->assertNotFound();
        $this->deleteJson('/api/admin/users/'.$owner->id)->assertOk();
    }

    public function test_agency_member_directory_is_limited_to_its_agency_and_profile_edit_is_self_only(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $otherAgency = Agency::create(['name' => 'Other Agency']);
        $member = User::factory()->create([
            'name' => 'Member',
            'role' => Role::AgencyMember,
            'agency_id' => $agency->id,
        ]);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]);

        $this->actingAs($member)
            ->getJson('/api/agency/directory')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonMissing(['id' => $member->id])
            ->assertJsonMissing(['email' => $member->email])
            ->assertJsonFragment(['id' => $owner->id, 'role' => Role::AgencyOwner->value]);

        $this->actingAs($owner)
            ->getJson('/api/agency/directory')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonMissing(['id' => $owner->id])
            ->assertJsonMissing(['email' => $owner->email])
            ->assertJsonFragment(['id' => $member->id, 'role' => Role::AgencyMember->value]);

        $this->actingAs($member);
        $this->patchJson('/api/agency/profile', ['name' => 'Updated Member'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Member');

        $this->assertDatabaseHas('users', ['id' => $member->id, 'name' => 'Updated Member']);

        $this->patchJson('/api/agency/profile', [
            'name' => 'Role escalation',
            'role' => Role::AgencyOwner->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);

        $this->assertDatabaseMissing('users', ['id' => $member->id, 'name' => 'Role escalation']);
    }

    public function test_admin_and_agency_directory_routes_reject_unauthorized_roles(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->getJson('/api/agency/directory')->assertUnauthorized();

        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($employee)->getJson('/api/admin/users')->assertForbidden();
        $this->getJson('/api/agency/directory')->assertForbidden();
    }

    public function test_only_agency_members_and_clients_can_view_the_agency_directory(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);

        foreach ([Role::AgencyMember, Role::AgencyClient] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'agency_id' => $agency->id,
            ]);

            $this->actingAs($user)
                ->getJson('/api/agency/directory')
                ->assertOk()
                ->assertJsonMissing(['id' => $user->id]);
        }

        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($employee)->getJson('/api/agency/directory')->assertForbidden();
    }

    public function test_agency_owner_and_client_can_view_shared_employees_on_the_team_screen(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $sharedEmployee = User::factory()->create([
            'name' => 'Shared employee',
            'role' => Role::Employee,
            'agency_id' => null,
        ]);
        $anotherSharedEmployee = User::factory()->create(['role' => Role::Employee, 'agency_id' => null]);
        User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);

        $this->actingAs($client)
            ->getJson('/api/agency/team')
            ->assertOk()
            ->assertJsonCount(2, 'employees')
            ->assertJsonFragment(['id' => $sharedEmployee->id, 'role' => Role::Employee->value])
            ->assertJsonFragment(['id' => $anotherSharedEmployee->id, 'role' => Role::Employee->value])
            ->assertJsonMissing(['id' => $owner->id]);

        $this->actingAs($owner)
            ->getJson('/api/agency/team')
            ->assertOk()
            ->assertJsonCount(2, 'employees')
            ->assertJsonFragment(['id' => $sharedEmployee->id, 'role' => Role::Employee->value]);

        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($employee)->getJson('/api/agency/team')->assertForbidden();
    }

    public function test_all_authenticated_roles_can_update_only_their_own_profile(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        foreach ([
            User::factory()->create(['role' => Role::Admin]),
            User::factory()->create(['role' => Role::Employee]),
            User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]),
            User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]),
        ] as $user) {
            $this->actingAs($user)
                ->patchJson('/api/auth/profile', ['name' => 'Updated profile'])
                ->assertOk()
                ->assertJsonPath('user.name', 'Updated profile');

            $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated profile']);
            $this->patchJson('/api/auth/profile', [
                'name' => 'Role escalation',
                'role' => Role::Admin->value,
            ])->assertUnprocessable()->assertJsonValidationErrors(['role']);
        }
    }
}
