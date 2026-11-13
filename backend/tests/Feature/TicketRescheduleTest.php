<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketRescheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_reorder_shifts_two_tickets_and_produces_two_events_with_correct_from_to_and_reason(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture(24);

        $t1 = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'First Task',
            'description' => 'Desc 1',
            'estimated_hours' => 6,
        ])->json('ticket');

        $t2 = $this->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Second Task',
            'description' => 'Desc 2',
            'estimated_hours' => 6,
        ])->json('ticket');

        $this->assertNotSame($t1['start_date'], $t2['start_date']);

        // Clear any previous events
        TicketEvent::where('type', 'rescheduled')->delete();

        // Reorder: put t2 before t1
        $res = $this->patchJson("/api/agency/projects/{$project->id}/tickets/reorder", [
            'ticket_ids' => [$t2['id'], $t1['id']],
        ])->assertOk();

        // Rescheduled events
        $rescheduledT1 = TicketEvent::where('ticket_id', $t1['id'])->where('type', 'rescheduled')->first();
        $rescheduledT2 = TicketEvent::where('ticket_id', $t2['id'])->where('type', 'rescheduled')->first();

        $this->assertNotNull($rescheduledT1);
        $this->assertNotNull($rescheduledT2);

        // Check T2 shifted to Monday
        $this->assertSame($t2['start_date'], $rescheduledT2->payload['from']['start']);
        $this->assertSame($t1['start_date'], $rescheduledT2->payload['to']['start']);
        $this->assertStringContainsString('was prioritised by '.$client->name, $rescheduledT2->payload['reason']);
        $this->assertStringContainsString('Moved from', $rescheduledT2->payload['reason']);

        // Check T1 shifted to Tuesday
        $this->assertSame($t1['start_date'], $rescheduledT1->payload['from']['start']);
        $this->assertSame($t2['start_date'], $rescheduledT1->payload['to']['start']);
        $this->assertStringContainsString('was prioritised by '.$client->name, $rescheduledT1->payload['reason']);
        $this->assertStringContainsString('Moved from', $rescheduledT1->payload['reason']);

        // Also check response contains rescheduled items
        $this->assertArrayHasKey('rescheduled', $res->json());
        $this->assertCount(2, $res->json('rescheduled'));
    }

    public function test_adding_a_ticket_after_the_others_produces_none(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture(24);

        $t1 = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Task A',
            'description' => 'Desc',
            'estimated_hours' => 6,
        ])->json('ticket');

        $t2 = $this->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Task B',
            'description' => 'Desc',
            'estimated_hours' => 6,
        ])->json('ticket');

        $countBefore = TicketEvent::where('type', 'rescheduled')->count();
        $this->assertSame(0, $countBefore);

        // Add Task C after the others
        $t3 = $this->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Task C',
            'description' => 'Desc',
            'estimated_hours' => 6,
        ])->json('ticket');

        $countAfter = TicketEvent::where('type', 'rescheduled')->count();
        $this->assertSame(0, $countAfter);
    }

    public function test_cancelling_the_first_ticket_produces_events_for_the_later_ones(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture(24);

        $t1 = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Task 1',
            'description' => 'Desc',
            'estimated_hours' => 6,
        ])->json('ticket');

        $t2 = $this->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Task 2',
            'description' => 'Desc',
            'estimated_hours' => 6,
        ])->json('ticket');

        // Cancel the first ticket
        $this->deleteJson("/api/agency/tickets/{$t1['id']}")->assertOk();

        // T1 is cancelled and should NOT have a rescheduled event
        $this->assertSame(0, TicketEvent::where('ticket_id', $t1['id'])->where('type', 'rescheduled')->count());

        // T2 moved earlier because T1 was cancelled
        $t2Event = TicketEvent::where('ticket_id', $t2['id'])->where('type', 'rescheduled')->first();
        $this->assertNotNull($t2Event);
        $this->assertSame($t1['key'], $t2Event->payload['cause']['ticket_key']);
        $this->assertStringContainsString("because {$t1['key']} was cancelled", $t2Event->payload['reason']);
        $this->assertSame($t1['start_date'], $t2Event->payload['to']['start']);
    }

    public function test_unchanged_tickets_produce_no_rescheduled_events(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture(24);

        $t1 = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Task 1',
            'description' => 'Desc',
            'estimated_hours' => 6,
        ])->json('ticket');

        $t2 = $this->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Task 2',
            'description' => 'Desc',
            'estimated_hours' => 6,
        ])->json('ticket');

        TicketEvent::where('type', 'rescheduled')->delete();

        // Update title only of T1 (no hours or date change)
        $this->patchJson("/api/agency/tickets/{$t1['id']}", [
            'title' => 'New Title Only',
        ])->assertOk();

        $this->assertSame(0, TicketEvent::where('type', 'rescheduled')->count());
    }

    /**
     * @return array{Agency, User, User, User, WorkItem}
     */
    private function projectFixture(float $hours = 24): array
    {
        $agency = Agency::create(['name' => 'Starlight', 'ticket_prefix' => 'STAR']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeek();

        $assignment = EmployeeAssignment::create([
            'holder_id' => $client->id,
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);

        $workItem = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => 'Project',
            'description' => 'Project description.',
            'estimated_hours' => $hours,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDays(5)->toDateString(),
            'status' => 'scheduled',
        ]);

        $remaining = (int) round($hours * 100);
        for ($date = $monday; $remaining > 0; $date = $date->addDay()) {
            if ($date->dayOfWeek === CarbonImmutable::SUNDAY) {
                continue;
            }

            $units = min(600, $remaining);
            $workItem->allocations()->create([
                'employee_id' => $employee->id,
                'work_date' => $date->toDateString(),
                'hours' => number_format($units / 100, 2, '.', ''),
            ]);
            $remaining -= $units;
        }

        return [$agency, $owner, $client, $employee, $workItem];
    }
}
