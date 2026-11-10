<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;
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
        Notification::assertSentTo(
            $client,
            SetPasswordNotification::class,
            fn (SetPasswordNotification $notification): bool => $notification->invite
                && str_contains($notification->toMail($client)->actionUrl, 'invite=1')
        );
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

    public function test_deactivating_an_employee_cancels_open_work_and_releases_future_allocations(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $agency = Agency::create(['name' => 'Northwind']);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $workDate = now()->addDays(2)->toDateString();
        $pastDate = now()->subDay()->toDateString();

        $scheduled = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'title' => 'Scheduled work',
            'description' => 'Should be cancelled.',
            'estimated_hours' => 2,
            'start_date' => $workDate,
            'due_date' => $workDate,
            'status' => 'scheduled',
        ]);
        $inProgress = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'title' => 'In-progress work',
            'description' => 'Should also be cancelled.',
            'estimated_hours' => 2,
            'start_date' => $workDate,
            'due_date' => $workDate,
            'status' => 'in_progress',
        ]);
        $completed = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'employee_id' => $employee->id,
            'title' => 'Completed work',
            'description' => 'Must remain completed.',
            'estimated_hours' => 2,
            'start_date' => $workDate,
            'due_date' => $workDate,
            'status' => 'completed',
        ]);
        foreach ([$scheduled, $inProgress, $completed] as $workItem) {
            $workItem->allocations()->create(['employee_id' => $employee->id, 'work_date' => $workDate, 'hours' => 2]);
        }
        $scheduled->allocations()->create(['employee_id' => $employee->id, 'work_date' => $pastDate, 'hours' => 1]);

        $this->actingAs($admin)
            ->patchJson('/api/admin/users/'.$employee->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('user.is_active', false);

        $this->assertDatabaseHas('work_items', ['id' => $scheduled->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('work_items', ['id' => $inProgress->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('work_items', ['id' => $completed->id, 'status' => 'completed']);
        $this->assertDatabaseMissing('work_item_allocations', [
            'employee_id' => $employee->id,
            'work_date' => $workDate,
        ]);
        $this->assertTrue(DB::table('work_item_allocations')
            ->where('work_item_id', $scheduled->id)
            ->whereDate('work_date', $pastDate)
            ->exists());
    }

    public function test_deactivating_an_assigned_employee_ends_the_assignment_and_releases_ticket_capacity(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $nextEmployee = User::factory()->create(['role' => Role::Employee]);
        $assignmentId = $this->actingAs($client)
            ->postJson('/api/agency/assignments', ['employee_id' => $employee->id])
            ->assertCreated()
            ->json('assignment.id');
        $workDate = now(config('app.timezone'))->addWeek()->toDateString();
        $workItem = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'assignment_id' => $assignmentId,
            'employee_id' => $employee->id,
            'title' => 'Assigned work',
            'description' => 'Must be cancelled with the assignment.',
            'estimated_hours' => 2,
            'start_date' => $workDate,
            'due_date' => $workDate,
            'status' => 'scheduled',
        ]);
        $workItem->allocations()->create([
            'employee_id' => $employee->id,
            'work_date' => $workDate,
            'hours' => 2,
        ]);
        $ticket = $this->postJson('/api/agency/projects/'.$workItem->id.'/tickets', [
            'title' => 'Assigned ticket',
            'description' => 'Ticket capacity must be released.',
            'estimated_hours' => 2,
        ])->assertCreated()->json('ticket');
        $this->assertDatabaseHas('ticket_allocations', [
            'ticket_id' => $ticket['id'],
            'work_date' => $workDate,
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/admin/users/'.$employee->id, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('user.is_active', false);

        $this->assertDatabaseHas('employee_assignments', [
            'id' => $assignmentId,
            'status' => 'ended',
            'active_employee_id' => null,
        ]);
        $this->assertDatabaseHas('work_items', ['id' => $workItem->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('tickets', ['id' => $ticket['id'], 'status' => 'cancelled']);
        $this->assertDatabaseMissing('ticket_allocations', [
            'ticket_id' => $ticket['id'],
            'work_date' => $workDate,
        ]);

        $this->actingAs($client)
            ->postJson('/api/agency/assignments', ['employee_id' => $nextEmployee->id])
            ->assertCreated();
        $this->assertSame(
            1,
            EmployeeAssignment::query()->where('holder_id', $client->id)->where('status', 'active')->count()
        );
    }

    public function test_admin_can_change_agency_status_and_suspension_revokes_only_agency_sessions(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $agency = Agency::create(['name' => 'Northwind']);
        $otherAgency = Agency::create(['name' => 'Southwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $otherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]);

        foreach ([['owner-session', $owner], ['client-session', $client], ['other-session', $otherClient]] as [$id, $user]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'payload' => '',
                'last_activity' => time(),
            ]);
        }

        $this->actingAs($admin)
            ->patchJson('/api/admin/agencies/'.$agency->id, ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('agency.id', $agency->id)
            ->assertJsonPath('agency.name', 'Northwind')
            ->assertJsonPath('agency.status', 'suspended');
        $this->assertDatabaseMissing('sessions', ['id' => 'owner-session']);
        $this->assertDatabaseMissing('sessions', ['id' => 'client-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);

        $this->patchJson('/api/admin/agencies/'.$agency->id, ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('agency.status', 'active');
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
    }

    public function test_only_admins_can_update_agency_status_and_status_is_validated(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $this->patchJson('/api/admin/agencies/'.$agency->id, ['status' => 'suspended'])
            ->assertUnauthorized();

        $owner = User::factory()->create([
            'role' => Role::AgencyOwner,
            'agency_id' => $agency->id,
        ]);
        $this->actingAs($owner)
            ->patchJson('/api/admin/agencies/'.$agency->id, ['status' => 'suspended'])
            ->assertForbidden();

        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)
            ->patchJson('/api/admin/agencies/'.$agency->id, ['status' => 'pending'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
        $this->assertDatabaseHas('agencies', ['id' => $agency->id, 'status' => 'active']);
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
