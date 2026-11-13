<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\WorkItemScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapacityPayloadAndCreatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_payloads_expose_the_capacity_rules_from_config(): void
    {
        [, $client, $employee, $assignment] = $this->fixture();

        $this->actingAs($client)->getJson('/api/agency/assignments')
            ->assertOk()
            ->assertJsonPath('assignments.0.capacity.daily_hours', 6)
            ->assertJsonPath('assignments.0.capacity.weekly_hours', 36);
        $this->getJson('/api/agency/assignments/'.$assignment->id.'/usage')
            ->assertOk()->assertJsonPath('capacity.daily_hours', 6)->assertJsonPath('capacity.weekly_hours', 36);
        $this->actingAs($employee)->getJson('/api/work-items/mine')
            ->assertOk()->assertJsonPath('capacity.daily_hours', 6)->assertJsonPath('capacity.weekly_hours', 36);
    }

    public function test_capacity_config_drives_the_scheduler_usage_and_payloads(): void
    {
        [, $client, , $assignment] = $this->fixture();
        config(['scheduling.daily_capacity_hours' => 4, 'scheduling.weekly_capacity_hours' => 24]);
        $monday = $this->monday();

        $this->assertSame(400, WorkItemScheduler::dailyCapacityUnits());
        $this->actingAs($client)->getJson('/api/agency/assignments')
            ->assertJsonPath('assignments.0.capacity.daily_hours', 4)
            ->assertJsonPath('assignments.0.capacity.weekly_hours', 24);
        $this->getJson('/api/agency/capacity?assignment_id='.$assignment->id.'&from='.$monday->toDateString().'&to='.$monday->addDay()->toDateString())
            ->assertOk()->assertJsonPath('total_available_hours', '8.00')->assertJsonPath('days.0.available_hours', '4.00');
        $this->getJson('/api/agency/assignments/'.$assignment->id.'/usage?week='.$monday->toDateString())
            ->assertOk()->assertJsonPath('remaining', '24.00');
        $this->postJson('/api/agency/projects', [
            'title' => 'Too big for 4 h days', 'description' => 'x', 'estimated_hours' => 9,
            'start_date' => $monday->toDateString(), 'due_date' => $monday->addDay()->toDateString(), 'assignment_id' => $assignment->id,
        ])->assertUnprocessable();
    }

    public function test_working_days_config_removes_a_weekday_from_the_schedule_and_the_usage_week(): void
    {
        [, $client, , $assignment] = $this->fixture();
        config(['scheduling.working_days' => [1, 2, 3, 4, 5]]);
        $monday = $this->monday();

        $this->actingAs($client)->getJson('/api/agency/capacity?assignment_id='.$assignment->id.'&from='.$monday->toDateString().'&to='.$monday->addDays(6)->toDateString())
            ->assertOk()
            ->assertJsonPath('total_available_hours', '30.00')
            ->assertJsonPath('days.5.reason', 'non_working_day')
            ->assertJsonPath('days.6.reason', 'sunday');
        $this->getJson('/api/agency/assignments/'.$assignment->id.'/usage?week='.$monday->toDateString())
            ->assertOk()->assertJsonCount(5, 'days');
    }

    public function test_usage_reports_consumed_hours_separately_from_what_is_still_bookable(): void
    {
        [$agency, $client, $employee, $assignment] = $this->fixture();
        $monday = $this->monday();
        $this->book($agency, $client, $employee, $assignment, $monday, 6, 'scheduled');
        $this->book($agency, $client, $employee, $assignment, $monday->addDay(), 6, 'completed');
        $this->book($agency, $client, $employee, $assignment, $monday->addDays(2), 6, 'cancelled');

        // used counts completed work (it was consumed) but not cancelled work; bookable_remaining
        // follows the scheduler, which frees completed and cancelled bookings.
        $this->actingAs($client)->getJson('/api/agency/assignments/'.$assignment->id.'/usage?week='.$monday->toDateString())
            ->assertOk()
            ->assertJsonPath('used', '12.00')
            ->assertJsonPath('remaining', '24.00')
            ->assertJsonPath('bookable_remaining', '30.00')
            ->assertJsonPath('days.0.used', '6.00')
            ->assertJsonPath('days.1.used', '6.00')
            ->assertJsonPath('days.2.used', '0.00');

        $this->getJson('/api/agency/assignments?week='.$monday->toDateString())
            ->assertOk()->assertJsonPath('assignments.0.weekly_usage.bookable_remaining', '30.00');
    }

    public function test_bookable_remaining_skips_non_working_dates_past_days_and_the_noon_cutoff(): void
    {
        [, $client, , $assignment] = $this->fixture();
        $wednesday = CarbonImmutable::now(config('app.timezone'))->next(CarbonImmutable::WEDNESDAY)->startOfDay();
        $monday = $wednesday->startOfWeek();
        config(['scheduling.non_working_dates' => [$wednesday->addDays(2)->toDateString()]]); // Friday

        $this->travelTo($wednesday->setTime(15, 0)); // after the cutoff: Wed is gone, Mon/Tue are past
        $this->actingAs($client)->getJson('/api/agency/assignments/'.$assignment->id.'/usage?week='.$monday->toDateString())
            ->assertOk()
            ->assertJsonPath('bookable_remaining', '12.00'); // Thu + Sat

        $this->travelTo($wednesday->setTime(9, 0));
        $this->getJson('/api/agency/assignments/'.$assignment->id.'/usage?week='.$monday->toDateString())
            ->assertJsonPath('bookable_remaining', '18.00'); // Wed + Thu + Sat
    }

    public function test_ticket_payloads_include_the_creator_everywhere(): void
    {
        [$agency, $client, $employee, $assignment] = $this->fixture();
        $project = $this->book($agency, $client, $employee, $assignment, $this->monday(), 6, 'scheduled');
        $ticket = Ticket::create([
            'work_item_id' => $project->id, 'created_by' => $client->id, 'title' => 'Made by client',
            'description' => 'Desc.', 'estimated_hours' => 2, 'position' => 0,
        ]);
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->getJson('/api/admin/tickets')
            ->assertOk()->assertJsonPath('tickets.0.creator.id', $client->id)->assertJsonPath('tickets.0.creator.name', $client->name);
        $this->getJson('/api/admin/projects/'.$project->id)
            ->assertOk()->assertJsonPath('tickets.0.creator.name', $client->name);
        $this->actingAs($client)->getJson('/api/agency/projects/'.$project->id.'/tickets')
            ->assertOk()->assertJsonPath('tickets.0.creator.name', $client->name);
        $this->actingAs($employee)->getJson('/api/work-items/mine/tickets')
            ->assertOk()->assertJsonPath('tickets.0.creator.name', $client->name);

        $this->actingAs($client)->postJson('/api/agency/projects/'.$project->id.'/tickets', [
            'title' => 'Second', 'description' => 'Desc.', 'estimated_hours' => 1,
        ])->assertCreated()->assertJsonPath('ticket.creator.name', $client->name);
    }

    public function test_creator_is_null_when_the_creating_account_was_deleted(): void
    {
        [$agency, $client, $employee, $assignment] = $this->fixture();
        $project = $this->book($agency, $client, $employee, $assignment, $this->monday(), 6, 'scheduled');
        $creator = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        Ticket::create([
            'work_item_id' => $project->id, 'created_by' => $creator->id, 'title' => 'Orphan',
            'description' => 'Desc.', 'estimated_hours' => 2, 'position' => 0,
        ]);
        $creator->delete();
        $admin = User::factory()->create(['role' => Role::Admin]);

        $this->actingAs($admin)->getJson('/api/admin/tickets')
            ->assertOk()->assertJsonPath('tickets.0.creator', null)->assertJsonPath('tickets.0.created_by', null);
        $this->getJson('/api/admin/projects/'.$project->id)->assertJsonPath('tickets.0.creator', null);
    }

    private function monday(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
    }

    /**
     * @return array{Agency, User, User, EmployeeAssignment}
     */
    private function fixture(): array
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = EmployeeAssignment::create([
            'holder_id' => $client->id, 'agency_id' => $agency->id, 'employee_id' => $employee->id,
            'status' => 'active', 'active_employee_id' => $employee->id, 'started_at' => now(),
        ]);

        return [$agency, $client, $employee, $assignment];
    }

    private function book(Agency $agency, User $client, User $employee, EmployeeAssignment $assignment, CarbonImmutable $date, float $hours, string $status): WorkItem
    {
        $item = WorkItem::create([
            'agency_id' => $agency->id, 'client_id' => $client->id, 'assignment_id' => $assignment->id,
            'employee_id' => $employee->id, 'title' => 'Booked '.$status, 'description' => 'Desc.',
            'estimated_hours' => $hours, 'start_date' => $date->toDateString(), 'due_date' => $date->toDateString(), 'status' => $status,
        ]);
        $item->allocations()->create(['employee_id' => $employee->id, 'work_date' => $date->toDateString(), 'hours' => $hours]);

        return $item;
    }
}
