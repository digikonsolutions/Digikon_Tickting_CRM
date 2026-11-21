<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;

/** Agencies, people, holds and booked projects for the dashboard and agency-page tests. */
trait BuildsAgencyFixtures
{
    protected CarbonImmutable $monday;

    protected CarbonImmutable $today;

    protected function setUpWeek(): void
    {
        $this->today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $this->monday = $this->today->startOfWeek(CarbonImmutable::MONDAY);
    }

    protected function makeAgency(string $name, string $status = 'active'): Agency
    {
        $agency = Agency::create(['name' => $name]);
        if ($status !== 'active') {
            $agency->update(['status' => $status]);
        }

        return $agency;
    }

    protected function makeOwner(Agency $agency, string $name = 'Owner'): User
    {
        return User::factory()->create(['name' => $name, 'role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
    }

    protected function makeClient(Agency $agency, string $name = 'Client'): User
    {
        return User::factory()->create(['name' => $name, 'role' => Role::AgencyClient, 'agency_id' => $agency->id]);
    }

    protected function makeEmployee(string $name, array $attributes = []): User
    {
        return User::factory()->create(['name' => $name, 'role' => Role::Employee, ...$attributes]);
    }

    protected function hold(User $employee, Agency $agency, User $holder): EmployeeAssignment
    {
        return EmployeeAssignment::create([
            'holder_id' => $holder->id,
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'source' => 'owner',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  list<array{CarbonImmutable, float}>  $allocations
     */
    protected function book(EmployeeAssignment $assignment, string $status, array $allocations, ?CarbonImmutable $due = null, ?string $title = null): WorkItem
    {
        $first = $allocations[0][0];
        $item = WorkItem::create([
            'agency_id' => $assignment->agency_id,
            'client_id' => $assignment->holder_id,
            'assignment_id' => $assignment->id,
            'employee_id' => $assignment->employee_id,
            'title' => $title ?? 'Project '.uniqid(),
            'description' => 'Desc.',
            'estimated_hours' => max(0.01, array_sum(array_column($allocations, 1))),
            'start_date' => $first->toDateString(),
            'due_date' => ($due ?? $this->today->addDays(20))->toDateString(),
            'status' => $status,
        ]);
        foreach ($allocations as [$date, $hours]) {
            $item->allocations()->create(['employee_id' => $assignment->employee_id, 'work_date' => $date->toDateString(), 'hours' => $hours]);
        }

        return $item;
    }
}
