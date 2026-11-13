<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgencyProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agency_user_can_fetch_a_single_project_from_their_agency(): void
    {
        [$agency, $owner, $client, $employee, $assignment] = $this->agencyFixture();
        $project = $this->makeProject($agency, $client, $employee, $assignment);

        foreach ([$owner, $client] as $viewer) {
            $this->actingAs($viewer)->getJson('/api/agency/projects/'.$project->id)
                ->assertOk()
                ->assertJsonPath('project.id', $project->id)
                ->assertJsonPath('project.assignment_id', $assignment->id)
                ->assertJsonPath('project.employee.id', $employee->id);
        }
    }

    public function test_single_project_is_hidden_from_other_agencies_and_wrong_roles(): void
    {
        [$agency, , $client, $employee, $assignment] = $this->agencyFixture();
        $project = $this->makeProject($agency, $client, $employee, $assignment);
        $otherAgency = Agency::create(['name' => 'Other agency']);
        $outsider = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]);

        $this->actingAs($outsider)->getJson('/api/agency/projects/'.$project->id)->assertNotFound();
        $this->actingAs($employee)->getJson('/api/agency/projects/'.$project->id)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))
            ->getJson('/api/agency/projects/'.$project->id)->assertForbidden();
        $this->actingAs($client)->getJson('/api/agency/projects/999999')->assertNotFound();
    }

    public function test_single_project_requires_authentication(): void
    {
        $this->getJson('/api/agency/projects/1')->assertUnauthorized();
    }

    public function test_single_project_is_forbidden_for_a_suspended_agency(): void
    {
        [$agency, , $client, $employee, $assignment] = $this->agencyFixture();
        $project = $this->makeProject($agency, $client, $employee, $assignment);
        $agency->update(['status' => 'suspended']);

        $this->actingAs($client)->getJson('/api/agency/projects/'.$project->id)->assertForbidden();
    }

    public function test_project_payload_reports_ticket_progress_everywhere_it_is_returned(): void
    {
        [$agency, $owner, $client, $employee, $assignment] = $this->agencyFixture();
        $project = $this->makeProject($agency, $client, $employee, $assignment);
        $this->addTickets($project, ['done', 'in_progress', 'todo', 'cancelled']);
        $expected = [
            'tickets_total' => 3,
            'tickets_done' => 1,
            'tickets_open' => 2,
        ];
        $assertProgress = function ($response, string $path) use ($expected, $project): void {
            foreach ($expected as $key => $value) {
                $response->assertJsonPath($path.'.'.$key, $value);
            }
            $response->assertJsonPath($path.'.next_ticket.title', 'Ticket 1')
                ->assertJsonPath($path.'.next_ticket.status', 'in_progress');
            $this->assertSame($project->tickets()->where('position', 1)->value('id'), $response->json($path.'.next_ticket.id'));
        };

        $assertProgress($this->actingAs($owner)->getJson('/api/agency/projects/'.$project->id)->assertOk(), 'project');
        $assertProgress($this->getJson('/api/agency/projects')->assertOk(), 'projects.0');
        $assertProgress($this->actingAs($employee)->getJson('/api/work-items/mine')->assertOk(), 'projects.0');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $assertProgress($this->actingAs($admin)->getJson('/api/admin/projects')->assertOk(), 'projects.0');
        $assertProgress($this->getJson('/api/admin/projects/'.$project->id)->assertOk(), 'project');
    }

    public function test_next_ticket_is_null_when_nothing_is_open_and_new_projects_start_empty(): void
    {
        [$agency, , $client, $employee, $assignment] = $this->agencyFixture();
        $project = $this->makeProject($agency, $client, $employee, $assignment);
        $this->addTickets($project, ['done', 'done', 'cancelled']);

        $this->actingAs($client)->getJson('/api/agency/projects/'.$project->id)
            ->assertJsonPath('project.tickets_total', 2)
            ->assertJsonPath('project.tickets_done', 2)
            ->assertJsonPath('project.tickets_open', 0)
            ->assertJsonPath('project.next_ticket', null);

        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(3);
        $this->actingAs($client)->postJson('/api/agency/projects', [
            'title' => 'Fresh',
            'description' => 'No tickets yet.',
            'estimated_hours' => 1,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDays(2)->toDateString(),
            'assignment_id' => $assignment->id,
        ])->assertCreated()
            ->assertJsonPath('project.tickets_total', 0)
            ->assertJsonPath('project.next_ticket', null);
    }

    public function test_cancelling_a_project_refreshes_its_ticket_progress(): void
    {
        [$agency, , $client, $employee, $assignment] = $this->agencyFixture();
        $project = $this->makeProject($agency, $client, $employee, $assignment);
        $this->addTickets($project, ['done', 'todo']);

        $this->actingAs($client)->patchJson('/api/agency/projects/'.$project->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('project.tickets_total', 1)
            ->assertJsonPath('project.tickets_done', 1)
            ->assertJsonPath('project.tickets_open', 0)
            ->assertJsonPath('project.next_ticket', null);
    }

    public function test_project_list_query_count_does_not_grow_with_the_number_of_projects(): void
    {
        [$agency, $owner, $client, $employee, $assignment] = $this->agencyFixture();
        foreach (range(1, 2) as $number) {
            $this->addTickets($this->makeProject($agency, $client, $employee, $assignment, title: 'P'.$number), ['done', 'todo']);
        }
        $this->actingAs($owner);

        $measure = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $this->getJson('/api/agency/projects')->assertOk(); // warm up
        $admin = User::factory()->create(['role' => Role::Admin]);
        $agencyBefore = $measure('/api/agency/projects');
        $this->actingAs($admin);
        $adminBefore = $measure('/api/admin/projects');
        $this->actingAs($employee);
        $mineBefore = $measure('/api/work-items/mine');

        foreach (range(3, 10) as $number) {
            $this->addTickets($this->makeProject($agency, $client, $employee, $assignment, title: 'P'.$number), ['done', 'in_progress', 'todo']);
        }

        $this->actingAs($owner);
        $this->assertSame($agencyBefore, $measure('/api/agency/projects'));
        $this->actingAs($admin);
        $this->assertSame($adminBefore, $measure('/api/admin/projects'));
        $this->actingAs($employee);
        $this->assertSame($mineBefore, $measure('/api/work-items/mine'));
    }

    public function test_project_list_is_paginated_and_reports_an_agency_wide_summary(): void
    {
        [$agency, $owner, $client, $employee, $assignment] = $this->agencyFixture();
        foreach (range(1, 7) as $number) {
            $this->makeProject($agency, $client, $employee, $assignment, title: 'Project '.$number);
        }
        $this->actingAs($owner);

        $first = $this->getJson('/api/agency/projects?per_page=3')->assertOk()
            ->assertJsonCount(3, 'projects')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 3)
            ->assertJsonPath('pagination.per_page', 3)
            ->assertJsonPath('pagination.total', 7)
            ->assertJsonPath('summary.total', 7)
            ->assertJsonPath('projects.0.title', 'Project 7');
        $this->getJson('/api/agency/projects?per_page=3&page=3')->assertOk()
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.title', 'Project 1')
            ->assertJsonPath('summary.total', 7);
        $this->assertSame(
            ['Project 7', 'Project 6', 'Project 5'],
            collect($first->json('projects'))->pluck('title')->all()
        );

        $this->getJson('/api/agency/projects')->assertOk()
            ->assertJsonCount(7, 'projects')
            ->assertJsonPath('pagination.per_page', 25);
        $this->getJson('/api/agency/projects?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/agency/projects?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_project_list_filters_by_status_assignment_and_search_while_summary_stays_agency_wide(): void
    {
        [$agency, $owner, $client, $employee, $assignment] = $this->agencyFixture();
        $secondEmployee = User::factory()->create(['role' => Role::Employee, 'name' => 'Zed Second']);
        $secondAssignment = EmployeeAssignment::create([
            'holder_id' => $client->id,
            'agency_id' => $agency->id,
            'employee_id' => $secondEmployee->id,
            'status' => 'active',
            'active_employee_id' => $secondEmployee->id,
            'started_at' => now(),
        ]);
        $this->makeProject($agency, $client, $employee, $assignment, title: 'Alpha site');
        $this->makeProject($agency, $client, $employee, $assignment, title: 'Beta app', status: 'in_progress');
        $this->makeProject($agency, $client, $employee, $assignment, title: 'Gamma', status: 'completed');
        $this->makeProject($agency, $client, $employee, $assignment, title: 'Delta', status: 'cancelled');
        $this->makeProject($agency, $client, $secondEmployee, $secondAssignment, title: 'Epsilon', status: 'scheduled');
        $this->actingAs($owner);
        $summary = ['total' => 5, 'scheduled' => 2, 'in_progress' => 1, 'completed' => 1, 'cancelled' => 1];

        $titles = fn (string $query): array => collect($this->getJson('/api/agency/projects?'.$query)->assertOk()
            ->assertJsonPath('summary.total', $summary['total'])
            ->assertJsonPath('summary.scheduled', $summary['scheduled'])
            ->assertJsonPath('summary.in_progress', $summary['in_progress'])
            ->assertJsonPath('summary.completed', $summary['completed'])
            ->assertJsonPath('summary.cancelled', $summary['cancelled'])
            ->json('projects'))->pluck('title')->sort()->values()->all();

        $this->assertSame(['Alpha site', 'Beta app', 'Epsilon'], $titles('status=open'));
        $this->assertSame(['Alpha site', 'Epsilon'], $titles('status=scheduled'));
        $this->assertSame(['Beta app'], $titles('status=in_progress'));
        $this->assertSame(['Gamma'], $titles('status=completed'));
        $this->assertSame(['Delta'], $titles('status=cancelled'));
        $this->assertSame(['Epsilon'], $titles('assignment_id='.$secondAssignment->id));
        $this->assertSame(['Alpha site', 'Beta app'], $titles('status=open&assignment_id='.$assignment->id));
        $this->assertSame(['Beta app'], $titles('search=beta'));
        $this->assertSame(['Epsilon'], $titles('search=Zed'));
        $this->assertSame([], $titles('status=completed&search=alpha'));
        $this->getJson('/api/agency/projects?status=bogus')->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->getJson('/api/agency/projects?status=completed')->assertOk()
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_project_list_never_leaks_other_agencies_projects_or_counts(): void
    {
        [$agency, $owner, $client, $employee, $assignment] = $this->agencyFixture();
        $this->makeProject($agency, $client, $employee, $assignment, title: 'Mine');
        [$otherAgency, $otherOwner, $otherClient, $otherEmployee, $otherAssignment] = $this->agencyFixture('Other agency');
        $this->makeProject($otherAgency, $otherClient, $otherEmployee, $otherAssignment, title: 'Theirs 1');
        $this->makeProject($otherAgency, $otherClient, $otherEmployee, $otherAssignment, title: 'Theirs 2', status: 'completed');

        $this->actingAs($owner)->getJson('/api/agency/projects')->assertOk()
            ->assertJsonCount(1, 'projects')
            ->assertJsonPath('projects.0.title', 'Mine')
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.completed', 0)
            ->assertJsonPath('pagination.total', 1);
        // Another agency's assignment id yields nothing, not their data.
        $this->getJson('/api/agency/projects?assignment_id='.$otherAssignment->id)->assertOk()
            ->assertJsonCount(0, 'projects')
            ->assertJsonPath('summary.total', 1);
        $this->actingAs($otherOwner)->getJson('/api/agency/projects')->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.completed', 1);
    }

    public function test_project_list_requires_authentication_and_an_agency_role(): void
    {
        [, , , $employee] = $this->agencyFixture();

        $this->getJson('/api/agency/projects')->assertUnauthorized();
        $this->actingAs($employee)->getJson('/api/agency/projects')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => Role::Admin]))->getJson('/api/agency/projects')->assertForbidden();
    }

    /**
     * @param list<string> $statuses
     */
    private function addTickets(WorkItem $project, array $statuses): void
    {
        foreach ($statuses as $position => $status) {
            Ticket::create([
                'work_item_id' => $project->id,
                'title' => 'Ticket '.$position,
                'description' => 'Ticket description.',
                'estimated_hours' => 1,
                'position' => $position,
                'status' => $status,
            ]);
        }
    }

    /**
     * @return array{Agency, User, User, User, EmployeeAssignment}
     */
    private function agencyFixture(string $name = 'Northwind'): array
    {
        $agency = Agency::create(['name' => $name]);
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

    private function makeProject(
        Agency $agency,
        User $client,
        User $employee,
        EmployeeAssignment $assignment,
        float $hours = 4,
        string $title = 'Project',
        string $status = 'scheduled',
    ): WorkItem {
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeek();
        $item = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => $title,
            'description' => 'Description.',
            'estimated_hours' => $hours,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDays(5)->toDateString(),
            'status' => $status,
        ]);
        $item->allocations()->create([
            'employee_id' => $employee->id,
            'work_date' => $monday->toDateString(),
            'hours' => number_format(min($hours, 6), 2, '.', ''),
        ]);

        return $item;
    }
}
