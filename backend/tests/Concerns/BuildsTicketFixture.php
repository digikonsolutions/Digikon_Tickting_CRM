<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;

/**
 * One agency (NORT) with an owner, two clients, an assigned employee and an admin, plus a project
 * with 30 scheduled hours next-next week. Users live in `$this->u`.
 */
trait BuildsTicketFixture
{
    /** @var array<string, User> */
    protected array $u = [];

    protected WorkItem $project;

    protected Agency $agency;

    protected function buildFixture(string $prefix = 'NORT'): void
    {
        $this->agency = Agency::create(['name' => 'Northwind '.$prefix, 'ticket_prefix' => $prefix]);
        $agency = $this->agency;
        $this->u['owner'] = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $this->u['client'] = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $this->u['other'] = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $this->u['employee'] = User::factory()->create(['role' => Role::Employee]);
        $this->u['admin'] = User::factory()->create(['role' => Role::Admin]);
        $assignment = EmployeeAssignment::create([
            'holder_id' => $this->u['client']->id, 'agency_id' => $agency->id, 'employee_id' => $this->u['employee']->id,
            'status' => 'active', 'active_employee_id' => $this->u['employee']->id, 'started_at' => now(),
        ]);
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
        $this->project = WorkItem::create([
            'agency_id' => $agency->id, 'client_id' => $this->u['client']->id, 'assignment_id' => $assignment->id,
            'employee_id' => $this->u['employee']->id, 'title' => 'Project', 'description' => 'Description.',
            'estimated_hours' => 30, 'start_date' => $monday->toDateString(), 'due_date' => $monday->addDays(5)->toDateString(),
            'status' => 'scheduled',
        ]);
        foreach (range(0, 4) as $offset) {
            $this->project->allocations()->create(['employee_id' => $this->u['employee']->id, 'work_date' => $monday->addDays($offset)->toDateString(), 'hours' => 6]);
        }
    }

    protected function makeTicket(string $by = 'other', string $title = 'Landing page', float $hours = 2): Ticket
    {
        $id = $this->actingAs($this->u[$by])->postJson('/api/agency/projects/'.$this->project->id.'/tickets', [
            'title' => $title, 'description' => 'Details.', 'estimated_hours' => $hours,
        ])->assertCreated()->json('ticket.id');

        return Ticket::findOrFail($id);
    }

    protected function transition(string $by, Ticket $ticket, string $action, ?string $comment = null): void
    {
        $this->actingAs($this->u[$by])->postJson('/api/tickets/'.$ticket->id.'/transitions', array_filter(['action' => $action, 'comment' => $comment]))->assertOk();
    }

    /** A second agency, for cross-tenant checks. */
    protected function outsider(): User
    {
        $agency = Agency::create(['name' => 'Other agency', 'ticket_prefix' => 'OTHR']);

        return User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
    }
}
