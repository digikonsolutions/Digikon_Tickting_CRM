<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/** The assigned employee starts and finishes their own project; tickets are optional. */
class EmployeeStartProjectTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $employee;

    private EmployeeAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scheduling.require_timeline_review' => false, 'scheduling.max_projects_in_progress' => 1]);
        $this->setUpWeek();
        $agency = $this->makeAgency('Northwind');
        $this->owner = $this->makeOwner($agency);
        $this->employee = $this->makeEmployee('Ed Employee');
        $this->assignment = $this->hold($this->employee, $agency, $this->owner);
    }

    private function project(string $title = 'Website'): WorkItem
    {
        return $this->book($this->assignment, 'scheduled', [[$this->monday->addDays(7), 3]], null, $title);
    }

    public function test_the_assigned_employee_starts_a_project_without_any_ticket(): void
    {
        $project = $this->project();

        $this->actingAs($this->employee)->postJson("/api/work-items/{$project->id}/start")->assertOk()
            ->assertJsonPath('project.status', 'in_progress')->assertJsonPath('project.started_at', fn ($value) => $value !== null);
        $this->assertSame('in_progress', $project->fresh()->status);
        $this->assertNotNull($project->fresh()->started_at);

        // Starting twice, or starting a project that is not scheduled, is refused.
        $this->postJson("/api/work-items/{$project->id}/start")->assertUnprocessable();
    }

    public function test_only_the_assigned_employee_can_start_or_finish_a_project(): void
    {
        $project = $this->project();
        $other = $this->makeEmployee('Eve Other');

        $this->actingAs($other)->postJson("/api/work-items/{$project->id}/start")->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/work-items/{$project->id}/start")->assertForbidden();
        $this->actingAs($this->employee)->postJson("/api/work-items/{$project->id}/start")->assertOk();
        $this->actingAs($other)->postJson("/api/work-items/{$project->id}/finish")->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/work-items/{$project->id}/finish")->assertForbidden();
        $this->assertSame('in_progress', $project->fresh()->status);
    }

    public function test_an_employee_has_one_project_in_progress_at_a_time(): void
    {
        $first = $this->project('First');
        $second = $this->project('Second');

        $this->actingAs($this->employee)->postJson("/api/work-items/{$first->id}/start")->assertOk();
        $this->postJson("/api/work-items/{$second->id}/start")->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->postJson("/api/work-items/{$first->id}/finish")->assertOk()->assertJsonPath('project.status', 'completed');
        $this->postJson("/api/work-items/{$second->id}/start")->assertOk();

        config(['scheduling.max_projects_in_progress' => 0]);   // 0 = no limit
        $third = $this->project('Third');
        $this->postJson("/api/work-items/{$third->id}/start")->assertOk();
    }

    public function test_finishing_waits_for_open_tickets(): void
    {
        $project = $this->project();
        $this->actingAs($this->employee)->postJson("/api/work-items/{$project->id}/start")->assertOk();
        Ticket::create([
            'agency_id' => $project->agency_id, 'work_item_id' => $project->id, 'created_by' => $this->owner->id, 'number' => 1,
            'title' => 'Open', 'description' => 'x', 'estimated_hours' => 1, 'priority' => 'normal', 'status' => 'todo', 'position' => 1,
        ]);

        $this->postJson("/api/work-items/{$project->id}/finish")->assertUnprocessable()->assertJsonValidationErrors('status');
        Ticket::query()->update(['status' => 'done']);
        $this->postJson("/api/work-items/{$project->id}/finish")->assertOk();
        $this->assertNotNull($project->fresh()->completed_at);
    }

    public function test_an_unconfirmed_or_awaiting_project_cannot_be_started(): void
    {
        config(['scheduling.require_timeline_review' => true]);
        $project = $this->project();
        $project->forceFill(['timeline_status' => 'proposed'])->save();
        $this->actingAs($this->employee)->postJson("/api/work-items/{$project->id}/start")->assertUnprocessable();

        $project->forceFill(['timeline_status' => 'confirmed', 'awaiting_schedule' => true])->save();
        $this->postJson("/api/work-items/{$project->id}/start")->assertUnprocessable();

        $project->forceFill(['awaiting_schedule' => false])->save();
        $this->postJson("/api/work-items/{$project->id}/start")->assertOk();
    }

    public function test_time_can_be_tracked_straight_on_a_project_without_tickets(): void
    {
        $project = $this->project();
        $this->actingAs($this->employee)->postJson("/api/work-items/{$project->id}/time/start")->assertUnprocessable();   // not started yet
        $this->postJson("/api/work-items/{$project->id}/start")->assertOk();

        $started = $this->postJson("/api/work-items/{$project->id}/time/start")->assertCreated()->assertJsonPath('entry.ticket_id', null)->assertJsonPath('entry.project.id', $project->id)->json('entry.id');
        $this->postJson("/api/work-items/{$project->id}/time/start")->assertUnprocessable();   // already running
        $this->getJson('/api/time/active')->assertOk()->assertJsonPath('entry.id', $started)->assertJsonPath('entry.project.title', $project->title);
        $this->postJson('/api/time/stop')->assertOk()->assertJsonPath('entry.running', false);

        $today = now(config('app.timezone'))->toDateString();
        $this->postJson("/api/work-items/{$project->id}/time", ['entry_date' => $today, 'minutes' => 90, 'note' => 'Setup'])->assertCreated()->assertJsonPath('entry.hours', 1.5);

        $week = $this->getJson('/api/time/week')->assertOk();
        $this->assertSame($project->id, $week->json('projects.0.project_id'));
        $this->assertGreaterThanOrEqual(1.5, $week->json('projects.0.logged_hours'));

        // Others cannot log on it.
        $this->actingAs($this->makeEmployee('Eve Other'))->postJson("/api/work-items/{$project->id}/time", ['entry_date' => $today, 'minutes' => 30])->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/work-items/{$project->id}/time", ['entry_date' => $today, 'minutes' => 30])->assertForbidden();
    }

    public function test_project_time_is_refused_once_it_has_tickets_and_stops_when_the_project_finishes(): void
    {
        $project = $this->project();
        $this->actingAs($this->employee)->postJson("/api/work-items/{$project->id}/start")->assertOk();
        $this->postJson("/api/work-items/{$project->id}/time/start")->assertCreated();

        $this->postJson("/api/work-items/{$project->id}/finish")->assertOk();
        $this->assertSame(0, \App\Models\TimeEntry::query()->whereNull('ended_at')->where('source', 'timer')->count(), 'finishing stops the running project timer');

        $other = $this->project('Second');
        $this->postJson("/api/work-items/{$other->id}/start")->assertOk();
        Ticket::create([
            'agency_id' => $other->agency_id, 'work_item_id' => $other->id, 'created_by' => $this->owner->id, 'number' => 2,
            'title' => 'T', 'description' => 'x', 'estimated_hours' => 1, 'priority' => 'normal', 'status' => 'todo', 'position' => 1,
        ]);
        $this->postJson("/api/work-items/{$other->id}/time/start")->assertUnprocessable()->assertJsonValidationErrors('project');
    }

    public function test_the_time_summary_counts_project_level_time(): void
    {
        $project = $this->project();
        $this->actingAs($this->employee)->postJson("/api/work-items/{$project->id}/start")->assertOk();
        $today = now(config('app.timezone'))->toDateString();
        $this->postJson("/api/work-items/{$project->id}/time", ['entry_date' => $today, 'minutes' => 120])->assertCreated();

        $admin = User::factory()->create(['role' => Role::Admin, 'is_active' => true]);
        $summary = $this->actingAs($admin)->getJson("/api/admin/time-summary?from={$today}&to={$today}&group_by=employee")->assertOk();
        $this->assertEquals(2.0, $summary->json('totals.logged_hours'));
    }
}