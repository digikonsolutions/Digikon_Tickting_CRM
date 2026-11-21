<?php

namespace Tests\Feature;

use App\Models\AgencyContract;
use App\Models\EmployeeAssignment;
use App\Models\Plan;
use App\Models\User;
use App\Services\EmployeeSeats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/** Who may add employees to an agency, and how many: seats, plan type, the "admin assigned nobody" exception, clients. */
class EmployeeSeatsTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWeek();
    }

    private function agencyOnPlan(bool $custom, ?int $seats, bool $adminAssigned): array
    {
        $agency = $this->makeAgency('Northwind '.uniqid());
        $owner = $this->makeOwner($agency);
        $client = $this->makeClient($agency);
        $plan = Plan::create(['name' => ($custom ? 'Custom plan ' : 'Standard ').uniqid(), 'is_custom' => $custom, 'employees' => $seats, 'hours_per_month' => 40, 'is_active' => true]);
        AgencyContract::create(['agency_id' => $agency->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'status' => 'active', 'start_date' => $this->today->toDateString(), 'employees' => $seats]);
        if ($adminAssigned) {
            EmployeeAssignment::create(['holder_id' => $owner->id, 'agency_id' => $agency->id, 'employee_id' => $this->makeEmployee('Assigned')->id, 'status' => 'active', 'source' => 'admin', 'active_employee_id' => null, 'started_at' => now()])
                ->update(['active_employee_id' => EmployeeAssignment::latest('id')->first()->employee_id]);
        }

        return [$agency, $owner, $client];
    }

    private function add(User $as, ?User $employee = null)
    {
        return $this->actingAs($as)->postJson('/api/agency/assignments', ['employee_id' => ($employee ?? $this->makeEmployee('New '.User::count()))->id]);
    }

    public function test_on_a_standard_plan_with_admin_assigned_employees_the_owner_cannot_add(): void
    {
        [, $owner] = $this->agencyOnPlan(custom: false, seats: 3, adminAssigned: true);

        $this->add($owner)->assertForbidden()->assertJsonPath('message', 'Employees are assigned by your account manager. Use “Request an employee” to ask for one.');
        $this->assertSame(1, EmployeeAssignment::count());
    }

    public function test_when_the_admin_assigned_nobody_the_owner_may_add_up_to_the_seat_limit_on_any_plan(): void
    {
        [, $owner] = $this->agencyOnPlan(custom: false, seats: 2, adminAssigned: false);

        $this->add($owner)->assertCreated();
        $this->add($owner)->assertCreated();
        $this->add($owner)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->assertSame(2, EmployeeAssignment::where('source', 'owner')->count());
    }

    public function test_on_the_custom_plan_the_owner_adds_up_to_the_custom_seats_even_with_admin_assigned_employees(): void
    {
        [$agency, $owner] = $this->agencyOnPlan(custom: true, seats: 3, adminAssigned: true);

        $this->add($owner)->assertCreated();
        $this->add($owner)->assertCreated();
        $this->add($owner)->assertUnprocessable()->assertJsonPath('errors.employee_id.0', 'All 3 employee seats are in use. Release an employee or request more seats.');

        // Releasing one frees a seat.
        EmployeeAssignment::where('agency_id', $agency->id)->where('source', 'owner')->first()->update(['status' => 'ended', 'active_employee_id' => null]);
        $this->add($owner)->assertCreated();
    }

    public function test_a_custom_plan_without_a_seat_limit_is_unlimited(): void
    {
        [, $owner] = $this->agencyOnPlan(custom: true, seats: null, adminAssigned: true);

        foreach (range(1, 4) as $_) {
            $this->add($owner)->assertCreated();
        }
    }

    public function test_clients_can_never_add_employees(): void
    {
        foreach ([[true, 5, false], [false, 5, false], [false, 5, true]] as [$custom, $seats, $admin]) {
            [, , $client] = $this->agencyOnPlan($custom, $seats, $admin);
            $this->add($client)->assertForbidden();
        }
        $this->assertSame(0, EmployeeAssignment::where('source', 'owner')->count());
    }

    public function test_another_agencys_assignments_do_not_use_up_seats(): void
    {
        [, $owner] = $this->agencyOnPlan(custom: true, seats: 1, adminAssigned: false);
        $rival = $this->makeAgency('Rival');
        $this->hold($this->makeEmployee('Theirs'), $rival, $this->makeClient($rival));

        $this->add($owner)->assertCreated();
    }

    public function test_the_summary_tells_the_ui_what_to_show(): void
    {
        [$agency, $owner] = $this->agencyOnPlan(custom: false, seats: 2, adminAssigned: true);
        $summary = app(EmployeeSeats::class)->summary($agency->id);

        $this->assertSame(['seats' => 2, 'used' => 1, 'remaining' => 1, 'full' => false, 'custom_plan' => false, 'admin_assigned' => true, 'can_add' => false], array_diff_key($summary, ['reason' => 1]));
        $this->assertStringContainsString('account manager', $summary['reason']);
        $this->assertNotNull($owner);
    }

    public function test_an_agency_with_no_contract_has_no_seat_limit_but_the_same_exception_rule(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);

        $this->add($owner)->assertCreated();
        $this->add($owner)->assertCreated();                          // no limit known, nobody assigned by an admin

        EmployeeAssignment::first()->update(['source' => 'admin']);
        $this->add($owner)->assertForbidden();                         // now an admin has assigned someone
    }
}
