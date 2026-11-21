<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\AgencyStatusNotice;
use App\Notifications\AssignmentEnded;
use App\Notifications\AssignmentHeld;
use App\Notifications\EmployeeDeactivatedNotice;
use App\Notifications\ProjectAssigned;
use App\Notifications\ProjectCancelled;
use App\Notifications\ProjectStatusChanged;
use App\Services\TicketNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private User $owner;

    private User $client;

    private User $employee;

    private User $admin;

    private EmployeeAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Acme Agency', 'ticket_prefix' => 'ACME']);
        $this->owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $this->agency->id, 'is_active' => true]);
        $this->client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $this->agency->id, 'is_active' => true]);
        $this->employee = User::factory()->create(['role' => Role::Employee, 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => Role::Admin, 'is_active' => true]);

        $this->assignment = EmployeeAssignment::create([
            'holder_id' => $this->client->id,
            'agency_id' => $this->agency->id,
            'employee_id' => $this->employee->id,
            'status' => 'active',
            'source' => 'owner',
            'active_employee_id' => $this->employee->id,
            'started_at' => now(),
        ]);
    }

    public function test_project_created_notifies_assigned_employee(): void
    {
        Notification::fake();

        $start = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
        $due = $start->addDays(4);

        $response = $this->actingAs($this->client)->postJson('/api/agency/projects', [
            'assignment_id' => $this->assignment->id,
            'title' => 'Brand Redesign',
            'description' => 'Project details.',
            'estimated_hours' => 20,
            'start_date' => $start->toDateString(),
            'due_date' => $due->toDateString(),
        ]);

        $response->assertCreated();

        Notification::assertSentTo($this->employee, ProjectAssigned::class, function ($n) {
            return str_contains($n->payload['title'], 'Brand Redesign')
                && $n->payload['url_path'] === '/my-work';
        });

        Notification::assertNotSentTo($this->client, ProjectAssigned::class);
        Notification::assertNotSentTo($this->owner, ProjectAssigned::class);
    }

    public function test_project_cancelled_notifies_employee_and_owners_except_actor(): void
    {
        $start = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
        $project = WorkItem::create([
            'agency_id' => $this->agency->id,
            'client_id' => $this->client->id,
            'assignment_id' => $this->assignment->id,
            'employee_id' => $this->employee->id,
            'title' => 'Cancelled Project',
            'description' => 'Will be cancelled.',
            'estimated_hours' => 10,
            'start_date' => $start->toDateString(),
            'due_date' => $start->addDays(2)->toDateString(),
            'status' => 'scheduled',
        ]);

        Notification::fake();

        $this->actingAs($this->client)->patchJson("/api/agency/projects/{$project->id}/cancel")->assertOk();

        Notification::assertSentTo($this->employee, ProjectCancelled::class);
        Notification::assertSentTo($this->owner, ProjectCancelled::class);
        Notification::assertNotSentTo($this->client, ProjectCancelled::class);
    }

    public function test_assignment_held_notifies_the_employee(): void
    {
        $otherEmployee = User::factory()->create(['role' => Role::Employee, 'is_active' => true]);

        Notification::fake();

        $this->actingAs($this->owner)->postJson('/api/agency/assignments', [
            'employee_id' => $otherEmployee->id,
        ])->assertCreated();

        Notification::assertSentTo($otherEmployee, AssignmentHeld::class, function ($n) {
            return str_contains($n->payload['body'], $this->owner->name)
                && $n->payload['url_path'] === '/my-work';
        });

        Notification::assertNotSentTo($this->owner, AssignmentHeld::class);
    }

    public function test_assignment_ended_notifies_employee_and_holder_except_actor(): void
    {
        Notification::fake();

        $this->actingAs($this->client)->deleteJson("/api/agency/assignments/{$this->assignment->id}")->assertOk();

        Notification::assertSentTo($this->employee, AssignmentEnded::class);
        Notification::assertNotSentTo($this->client, AssignmentEnded::class);
    }

    public function test_employee_deactivated_notifies_clients_with_open_projects(): void
    {
        $start = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
        WorkItem::create([
            'agency_id' => $this->agency->id,
            'client_id' => $this->client->id,
            'assignment_id' => $this->assignment->id,
            'employee_id' => $this->employee->id,
            'title' => 'Open Project',
            'description' => 'Test',
            'estimated_hours' => 10,
            'start_date' => $start->toDateString(),
            'due_date' => $start->addDays(2)->toDateString(),
            'status' => 'scheduled',
        ]);

        Notification::fake();

        $this->actingAs($this->admin)->patchJson("/api/admin/users/{$this->employee->id}", [
            'is_active' => false,
        ])->assertOk();

        Notification::assertSentTo($this->client, EmployeeDeactivatedNotice::class);
    }

    public function test_agency_status_changed_notifies_agency_owner(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->patchJson("/api/admin/agencies/{$this->agency->id}", [
            'status' => 'suspended',
        ])->assertOk();

        Notification::assertSentTo($this->owner, AgencyStatusNotice::class, function ($n) {
            return str_contains($n->payload['body'], 'suspended');
        });
    }

    public function test_inactive_recipients_are_skipped(): void
    {
        $this->employee->update(['is_active' => false]);

        $start = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
        $project = WorkItem::create([
            'agency_id' => $this->agency->id,
            'client_id' => $this->client->id,
            'assignment_id' => $this->assignment->id,
            'employee_id' => $this->employee->id,
            'title' => 'Test Inactive',
            'description' => 'Desc',
            'estimated_hours' => 10,
            'start_date' => $start->toDateString(),
            'due_date' => $start->addDays(2)->toDateString(),
            'status' => 'scheduled',
        ]);

        Notification::fake();

        $this->actingAs($this->client)->patchJson("/api/agency/projects/{$project->id}/cancel")->assertOk();

        Notification::assertNotSentTo($this->employee, ProjectCancelled::class);
        Notification::assertSentTo($this->owner, ProjectCancelled::class);
    }

    public function test_notification_delivery_failure_does_not_break_http_response(): void
    {
        // Mock TicketNotifier to simulate exception during send
        $mock = $this->createMock(TicketNotifier::class);
        $mock->expects($this->any())
            ->method('send')
            ->willThrowException(new \RuntimeException('Simulated SMTP connection failure'));

        $this->app->instance(TicketNotifier::class, $mock);

        $start = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
        $project = WorkItem::create([
            'agency_id' => $this->agency->id,
            'client_id' => $this->client->id,
            'assignment_id' => $this->assignment->id,
            'employee_id' => $this->employee->id,
            'title' => 'Test Failure',
            'description' => 'Desc',
            'estimated_hours' => 10,
            'start_date' => $start->toDateString(),
            'due_date' => $start->addDays(2)->toDateString(),
            'status' => 'scheduled',
        ]);

        // Cancelling the project should succeed even if notification throws
        $response = $this->actingAs($this->client)->patchJson("/api/agency/projects/{$project->id}/cancel");
        $response->assertOk();

        $this->assertDatabaseHas('work_items', [
            'id' => $project->id,
            'status' => 'cancelled',
        ]);
    }
}
