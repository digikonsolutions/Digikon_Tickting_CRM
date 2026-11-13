<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_available_hours_matches_exactly_what_a_project_can_be_scheduled_for(): void
    {
        [$agency, , $client, $employee, $assignment] = $this->fixture();
        $monday = $this->nextMonday();
        $saturday = $monday->addDays(5);
        // Another agency previously held this employee and still has 4 h booked on Tuesday.
        $this->bookElsewhere($employee, $monday->addDay(), 4);

        $response = $this->actingAs($client)->getJson($this->capacityUrl($assignment, $monday, $saturday))
            ->assertOk()
            ->assertJsonPath('assignment_id', $assignment->id)
            ->assertJsonPath('from', $monday->toDateString())
            ->assertJsonPath('to', $saturday->toDateString())
            ->assertJsonPath('total_available_hours', '32.00')
            ->assertJsonCount(6, 'days')
            ->assertJsonPath('days.1.booked_hours', '4.00')
            ->assertJsonPath('days.1.available_hours', '2.00');
        $this->assertSame('32.00', $response->json('total_available_hours'));

        $payload = fn (float $hours): array => [
            'title' => 'Exactly fits',
            'description' => 'Uses all remaining capacity.',
            'estimated_hours' => $hours,
            'start_date' => $monday->toDateString(),
            'due_date' => $saturday->toDateString(),
            'assignment_id' => $assignment->id,
        ];

        $this->postJson('/api/agency/projects', $payload(33))->assertUnprocessable()->assertJsonValidationErrors('assignment_id');
        $this->postJson('/api/agency/projects', $payload(32))->assertCreated();

        $this->getJson($this->capacityUrl($assignment, $monday, $saturday))
            ->assertOk()
            ->assertJsonPath('total_available_hours', '0.00');
    }

    public function test_sundays_and_non_working_dates_are_reported_with_a_reason_and_zero_hours(): void
    {
        [, , $client, , $assignment] = $this->fixture();
        $monday = $this->nextMonday();
        config(['scheduling.non_working_dates' => [$monday->addDays(2)->toDateString()]]);

        $response = $this->actingAs($client)
            ->getJson($this->capacityUrl($assignment, $monday, $monday->addDays(6)))
            ->assertOk()
            ->assertJsonPath('total_available_hours', '30.00')
            ->assertJsonPath('days.2.working_day', false)
            ->assertJsonPath('days.2.reason', 'non_working_day')
            ->assertJsonPath('days.2.available_hours', '0.00')
            ->assertJsonPath('days.6.working_day', false)
            ->assertJsonPath('days.6.reason', 'sunday')
            ->assertJsonPath('days.0.working_day', true)
            ->assertJsonPath('days.0.reason', null)
            ->assertJsonPath('days.0.available_hours', '6.00');
        $this->assertSame($monday->toDateString(), $response->json('days.0.date'));
    }

    public function test_today_is_unavailable_after_the_cutoff_hour_and_available_before_it(): void
    {
        [, , $client, , $assignment] = $this->fixture();
        $wednesday = CarbonImmutable::now(config('app.timezone'))->next(CarbonImmutable::WEDNESDAY)->startOfDay();

        $this->travelTo($wednesday->setTime(15, 0));
        $this->actingAs($client)->getJson($this->capacityUrl($assignment, $wednesday, $wednesday->addDay()))
            ->assertOk()
            ->assertJsonPath('days.0.reason', 'past_cutoff')
            ->assertJsonPath('days.0.working_day', false)
            ->assertJsonPath('days.0.available_hours', '0.00')
            ->assertJsonPath('days.1.reason', null)
            ->assertJsonPath('total_available_hours', '6.00');

        $this->travelTo($wednesday->setTime(9, 0));
        $this->getJson($this->capacityUrl($assignment, $wednesday, $wednesday->addDay()))
            ->assertOk()
            ->assertJsonPath('days.0.reason', null)
            ->assertJsonPath('days.0.available_hours', '6.00')
            ->assertJsonPath('total_available_hours', '12.00');
    }

    public function test_bookings_from_other_agencies_reduce_availability_but_completed_ones_do_not(): void
    {
        [, , $client, $employee, $assignment] = $this->fixture();
        $monday = $this->nextMonday();
        $this->bookElsewhere($employee, $monday, 6);
        $this->bookElsewhere($employee, $monday->addDay(), 5, 'completed');

        $this->actingAs($client)->getJson($this->capacityUrl($assignment, $monday, $monday->addDay()))
            ->assertOk()
            ->assertJsonPath('days.0.booked_hours', '6.00')
            ->assertJsonPath('days.0.available_hours', '0.00')
            ->assertJsonPath('days.1.booked_hours', '0.00')
            ->assertJsonPath('days.1.available_hours', '6.00')
            ->assertJsonPath('total_available_hours', '6.00');
    }

    public function test_capacity_is_scoped_to_the_callers_agency_and_active_assignments(): void
    {
        [$agency, $owner, $client, $employee, $assignment] = $this->fixture();
        $monday = $this->nextMonday();
        $url = $this->capacityUrl($assignment, $monday, $monday->addDays(2));

        $this->actingAs($client)->getJson($url)->assertOk();
        // Assignments are private to their holder, even for the agency owner.
        $this->actingAs($owner)->getJson($url)->assertNotFound();

        $otherAgency = Agency::create(['name' => 'Other agency']);
        $outsider = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]);
        $this->actingAs($outsider)->getJson($url)->assertNotFound();
        $this->actingAs($client)->getJson($this->capacityUrl(999999, $monday, $monday->addDay()))->assertNotFound();

        $assignment->update(['status' => 'ended', 'active_employee_id' => null, 'ended_at' => now()]);
        $this->actingAs($client)->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('assignment_id');
    }

    public function test_capacity_rejects_wrong_roles_guests_and_suspended_agencies(): void
    {
        [$agency, , $client, $employee, $assignment] = $this->fixture();
        $monday = $this->nextMonday();
        $url = $this->capacityUrl($assignment, $monday, $monday->addDay());

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($employee)->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))->getJson($url)->assertForbidden();

        $agency->update(['status' => 'suspended']);
        $this->actingAs($client)->getJson($url)->assertForbidden();
    }

    public function test_capacity_validates_its_query_parameters(): void
    {
        [, , $client, , $assignment] = $this->fixture();
        $monday = $this->nextMonday();
        $this->actingAs($client);

        $this->getJson('/api/agency/capacity')->assertUnprocessable()
            ->assertJsonValidationErrors(['assignment_id', 'from', 'to']);
        $this->getJson($this->capacityUrl($assignment, $monday, $monday->subDay()))
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/agency/capacity?assignment_id='.$assignment->id.'&from=11/14/2026&to=2026-11-20')
            ->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson($this->capacityUrl($assignment, $monday, $monday->addDays(367)))
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson($this->capacityUrl($assignment, $monday, $monday->addDays(366)))
            ->assertOk()->assertJsonCount(367, 'days');
    }

    private function nextMonday(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
    }

    private function capacityUrl(EmployeeAssignment|int $assignment, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $id = $assignment instanceof EmployeeAssignment ? $assignment->id : $assignment;

        return '/api/agency/capacity?assignment_id='.$id.'&from='.$from->toDateString().'&to='.$to->toDateString();
    }

    /**
     * @return array{Agency, User, User, User, EmployeeAssignment}
     */
    private function fixture(): array
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = EmployeeAssignment::create([
            'holder_id' => $client->id,
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);

        return [$agency, $owner, $client, $employee, $assignment];
    }

    /**
     * A booking by a different agency on the same (shared) employee.
     */
    private function bookElsewhere(User $employee, CarbonImmutable $date, float $hours, string $status = 'scheduled'): void
    {
        $other = Agency::create(['name' => 'Previous agency '.$date->toDateString().$status]);
        $otherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $other->id]);
        $assignment = EmployeeAssignment::create([
            'holder_id' => $otherClient->id,
            'agency_id' => $other->id,
            'employee_id' => $employee->id,
            'status' => 'ended',
            'active_employee_id' => null,
            'started_at' => now()->subMonth(),
            'ended_at' => now()->subDay(),
        ]);
        $item = WorkItem::create([
            'agency_id' => $other->id,
            'client_id' => $otherClient->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => 'Other agency work',
            'description' => 'Booked earlier.',
            'estimated_hours' => $hours,
            'start_date' => $date->toDateString(),
            'due_date' => $date->toDateString(),
            'status' => $status,
        ]);
        $item->allocations()->create([
            'employee_id' => $employee->id,
            'work_date' => $date->toDateString(),
            'hours' => number_format($hours, 2, '.', ''),
        ]);
    }
}
