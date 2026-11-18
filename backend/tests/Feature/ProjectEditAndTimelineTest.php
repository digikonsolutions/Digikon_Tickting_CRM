<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmployeeActivityLog;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\MonitoringPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

class ProjectEditAndTimelineTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
        app(MonitoringPolicy::class)->accept($this->u['employee'], null);
    }

    private function url(?int $id = null): string
    {
        return '/api/agency/projects/'.($id ?? $this->project->id);
    }

    /* ---- edit project ----------------------------------------------------------------------- */

    public function test_owner_edits_an_open_project_and_changes_persist(): void
    {
        $this->actingAs($this->u['owner'])->patchJson($this->url(), [
            'title' => 'Renamed project',
            'description' => 'New description.',
            'brief_priority' => 'urgent',
            'objective' => 'Ship it.',
        ])->assertOk()
            ->assertJsonPath('project.title', 'Renamed project')
            ->assertJsonPath('project.brief.brief_priority', 'urgent');

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('project.title', 'Renamed project')
            ->assertJsonPath('project.description', 'New description.')
            ->assertJsonPath('project.brief.objective', 'Ship it.')
            ->assertJsonPath('project.status', 'scheduled');
    }

    public function test_owner_edits_a_completed_project_without_changing_its_status_tickets_or_employee(): void
    {
        $ticket = $this->makeTicket('client', 'Original ticket', 2);
        $this->project->update(['status' => 'completed']);

        $this->actingAs($this->u['owner'])->patchJson($this->url(), ['title' => 'Edited after completion', 'brief_priority' => 'high'])
            ->assertOk()
            ->assertJsonPath('project.status', 'completed')
            ->assertJsonPath('project.employee.id', $this->u['employee']->id);

        $this->assertSame('completed', $this->project->fresh()->status);
        $this->assertSame($this->u['employee']->id, $this->project->fresh()->employee_id);
        $this->assertSame('Original ticket', $ticket->fresh()->title);
        $this->assertSame(1, $this->project->tickets()->count());
    }

    public function test_hours_dates_and_employee_cannot_change_on_a_completed_project(): void
    {
        $this->project->update(['status' => 'completed']);

        $this->actingAs($this->u['owner'])->patchJson($this->url(), ['due_date' => now()->addMonth()->toDateString(), 'start_date' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_status_can_never_be_set_through_edit_and_required_fields_are_validated(): void
    {
        $this->actingAs($this->u['owner'])->patchJson($this->url(), ['status' => 'cancelled'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson($this->url(), ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->patchJson($this->url(), ['brief_priority' => 'whenever'])->assertUnprocessable()->assertJsonValidationErrors('brief_priority');
        $this->patchJson($this->url(), ['due_date' => '2020-01-01', 'start_date' => '2020-02-01'])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->assertSame('scheduled', $this->project->fresh()->status);
    }

    public function test_only_the_agency_owner_of_that_agency_may_edit(): void
    {
        foreach (['client', 'employee', 'admin'] as $who) {
            $this->actingAs($this->u[$who])->patchJson($this->url(), ['title' => 'Nope'])->assertForbidden();
        }
        $this->actingAs($this->outsider())->patchJson($this->url(), ['title' => 'Cross agency'])->assertNotFound();
        $this->assertSame('Project', $this->project->fresh()->title);
    }

    public function test_changing_hours_replans_the_open_project(): void
    {
        $this->makeTicket('client', 'Ticket', 2);
        $this->actingAs($this->u['owner'])->patchJson($this->url(), ['estimated_hours' => 20])
            ->assertOk()->assertJsonPath('project.estimated_hours', '20.00');
        $this->assertEqualsWithDelta(20.0, (float) $this->project->allocations()->sum('hours'), 0.01);
    }

    /* ---- tickets on completed projects ------------------------------------------------------ */

    public function test_owner_can_create_a_ticket_on_a_completed_project_and_it_reopens_with_a_future_due_date(): void
    {
        $this->project->update(['status' => 'completed']);

        $response = $this->actingAs($this->u['owner'])->postJson($this->url().'/tickets', [
            'title' => 'Follow-up', 'description' => 'Extra work.', 'estimated_hours' => 2, 'priority' => 'high',
        ])->assertCreated();

        $ticketId = $response->json('ticket.id');
        $this->assertSame($this->project->id, Ticket::findOrFail($ticketId)->work_item_id);
        $this->assertSame($this->agency->id, Ticket::findOrFail($ticketId)->agency_id);
        // A follow-up ticket must reopen the project and get real, future allocation: squeezing it into
        // the project's already-past schedule window would make it look overdue the moment it is created.
        $this->assertSame('in_progress', $this->project->fresh()->status);
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $this->assertGreaterThanOrEqual($today, (string) $response->json('ticket.due_date'));
        $this->getJson($this->url().'/tickets')->assertOk()->assertJsonFragment(['title' => 'Follow-up']);
    }

    public function test_follow_up_ticket_does_not_reuse_unused_slack_from_the_old_schedule_window(): void
    {
        // The project budgets 30 h but the only ticket that actually ran used just 4 h, so the original
        // allocation pool (Monday 6 h, ... ) is left with a lot of now-past, never-consumed slack once
        // the project completes. A follow-up ticket must never be dated into that stale slack.
        $small = $this->makeTicket('client', 'Small done ticket', 4);
        $this->transition('employee', $small, 'start');
        $this->transition('employee', $small, 'send_to_review');
        $this->transition('client', $small, 'approve');
        $this->assertSame('completed', $this->project->fresh()->status);

        $followUp = $this->actingAs($this->u['owner'])->postJson($this->url().'/tickets', [
            'title' => 'Follow-up', 'description' => 'Extra work.', 'estimated_hours' => 1,
        ])->assertCreated()->json('ticket');

        $this->assertSame('in_progress', $this->project->fresh()->status);
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $this->assertGreaterThanOrEqual($today, $followUp['due_date'], 'the follow-up must not land in the old, already-past schedule window');

        $this->actingAs($this->u['employee'])->postJson('/api/tickets/'.$followUp['id'].'/transitions', ['action' => 'start'])
            ->assertOk()->assertJsonPath('ticket.status', 'in_progress');
    }

    public function test_cancelled_projects_still_reject_new_tickets(): void
    {
        $this->project->update(['status' => 'cancelled']);
        $this->actingAs($this->u['owner'])->postJson($this->url().'/tickets', ['title' => 'x', 'description' => 'y', 'estimated_hours' => 1])->assertUnprocessable();
    }

    /* ---- project employee timeline ---------------------------------------------------------- */

    public function test_owner_sees_the_project_timeline_filtered_to_that_project(): void
    {
        $ticket = $this->makeTicket('client', 'Landing', 4);
        $this->transition('employee', $ticket, 'start');
        // An event on another project of the same employee must not appear.
        EmployeeActivityLog::create(['employee_id' => $this->u['employee']->id, 'event_type' => 'login', 'occurred_at' => now()]);

        $response = $this->actingAs($this->u['owner'])->getJson($this->url().'/activity')->assertOk();
        $response->assertJsonPath('employee.id', $this->u['employee']->id)->assertJsonPath('project.id', $this->project->id);
        $types = collect($response->json('events'))->pluck('event_type')->all();
        $this->assertContains('ticket_status_changed', $types);
        $this->assertNotContains('login', $types);
        foreach ($response->json('events') as $event) {
            $this->assertNull($event['ip_address']);
        }
    }

    public function test_project_timeline_blocks_other_agencies_and_other_roles(): void
    {
        $this->actingAs($this->outsider())->getJson($this->url().'/activity')->assertNotFound();
        foreach (['client', 'employee', 'admin'] as $who) {
            $this->actingAs($this->u[$who])->getJson($this->url().'/activity')->assertForbidden();
        }
        $this->u['lead'] = User::factory()->create(['role' => Role::TeamLead]);
        $this->actingAs($this->u['lead'])->getJson($this->url().'/activity')->assertForbidden();

        EmployeeAssignment::firstOrFail()->forceFill(['status' => 'ended', 'active_employee_id' => null, 'ended_at' => now()])->save();
        $this->actingAs($this->u['owner'])->getJson($this->url().'/activity')->assertNotFound();
    }
}
