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
    private function agencyFixture(): array
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
