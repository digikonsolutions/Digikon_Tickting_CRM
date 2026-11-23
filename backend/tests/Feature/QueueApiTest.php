<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmployeeAssignment;
use App\Models\QueueEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\AssignmentService;
use App\Services\EmployeeQueuePlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

class QueueApiTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $client;

    private User $employee;

    private User $admin;

    private EmployeeAssignment $assignment;

    private CarbonImmutable $mon;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests cover the engine behind the older flow where an agency's own dates are auto-scheduled.
        config(['scheduling.admin_only_scheduling' => false]);
        config(['scheduling.require_timeline_review' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-12-07 08:00', config('app.timezone')));
        $this->setUpWeek();
        $this->mon = $this->today;
        $agency = $this->makeAgency('Acme');
        $this->owner = $this->makeOwner($agency);
        $this->client = $this->makeClient($agency);
        $this->employee = $this->makeEmployee('Emma');
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->assignment = $this->hold($this->employee, $agency, $this->owner);
    }

    private function item(string $title, float $hours, int $dueOffset = 12, ?EmployeeAssignment $assignment = null, ?User $creator = null): WorkItem
    {
        $assignment ??= $this->assignment;
        $planner = app(EmployeeQueuePlanner::class);
        $employee = $assignment->employee;
        $item = WorkItem::create([
            'agency_id' => $assignment->agency_id,
            'client_id' => ($creator ?? $this->client)->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => $title,
            'description' => 'Desc.',
            'estimated_hours' => $hours,
            'start_date' => $this->mon->toDateString(),
            'due_date' => $this->mon->addDays($dueOffset)->toDateString(),
            'status' => 'scheduled',
            'queue_position' => $planner->nextPosition($employee),
        ]);
        $planner->replan($employee);

        return $item;
    }

    public function test_queue_shape_has_now_up_next_and_capacity(): void
    {
        $a = $this->item('First', 12);
        $b = $this->item('Second', 6);

        $json = $this->actingAs($this->owner)->getJson("/api/agency/assignments/{$this->assignment->id}/queue")
            ->assertOk()
            ->assertJsonStructure([
                'employee' => ['id', 'name'],
                'now',
                'up_next' => [['work_item_id', 'title', 'client_name', 'estimated_hours', 'planned_start', 'planned_end', 'due_date', 'status', 'fits']],
                'capacity' => ['hours_today_used', 'hours_week_used', 'hours_week_remaining', 'free_from'],
            ])
            ->assertJsonPath('up_next.0.work_item_id', $a->id)
            ->assertJsonPath('up_next.1.work_item_id', $b->id)
            ->assertJsonPath('up_next.0.planned_start', $this->mon->toDateString())
            ->assertJsonPath('up_next.0.planned_end', $this->mon->addDay()->toDateString())
            ->assertJsonPath('up_next.0.fits', true)
            // With nothing in progress, "now" is today's scheduled hours.
            ->assertJsonPath('now.kind', 'scheduled')
            ->assertJsonPath('now.work_item_id', $a->id)
            ->assertJsonPath('capacity.hours_today_used', '6.00')
            ->assertJsonPath('capacity.hours_week_used', '18.00')
            ->assertJsonPath('capacity.hours_week_remaining', '18.00')
            // Mon, Tue and Wed are full, Thursday is the first day with room.
            ->assertJsonPath('capacity.free_from.date', $this->mon->addDays(3)->toDateString());

        $this->assertArrayNotHasKey('agency_id', $json->json('up_next.0'));
    }

    public function test_now_is_the_ticket_in_progress_with_hours_logged(): void
    {
        $a = $this->item('Started', 12);
        $ticket = Ticket::create([
            'agency_id' => $a->agency_id, 'work_item_id' => $a->id, 'created_by' => $this->owner->id, 'number' => 1,
            'title' => 'Build header', 'description' => 'x', 'estimated_hours' => 4, 'priority' => 'normal',
            'status' => 'in_progress', 'position' => 1,
        ]);
        $this->assertNotNull($ticket->fresh()->started_at, 'moving a ticket to in progress stamps started_at');
        $ticket->timeEntries()->create(['work_item_id' => $a->id, 'entry_date' => now()->toDateString(), 'user_id' => $this->employee->id, 'started_at' => now()->subHours(2), 'ended_at' => now()->subHour(), 'minutes' => 60]);
        $b = $this->item('Other', 6);

        $this->actingAs($this->owner)->getJson("/api/agency/assignments/{$this->assignment->id}/queue")
            ->assertOk()
            ->assertJsonPath('now.kind', 'ticket')
            ->assertJsonPath('now.title', 'Build header')
            ->assertJsonPath('now.project_title', 'Started')
            ->assertJsonPath('now.hours_logged', 1)
            ->assertJsonPath('now.hours_estimated', 4)
            ->assertJsonPath('pinned_work_item_ids.0', $a->id)
            ->assertJsonCount(1, 'up_next')
            ->assertJsonPath('up_next.0.work_item_id', $b->id);
    }

    public function test_an_empty_queue_has_no_now(): void
    {
        $this->actingAs($this->owner)->getJson("/api/agency/assignments/{$this->assignment->id}/queue")
            ->assertOk()->assertJsonPath('now', null)->assertJsonCount(0, 'up_next');
    }

    public function test_only_the_owner_can_preview_and_reorder(): void
    {
        $a = $this->item('First', 12);
        $b = $this->item('Second', 6);
        $order = ['ordered_work_item_ids' => [$b->id, $a->id]];
        $url = "/api/agency/assignments/{$this->assignment->id}/queue";

        $this->actingAs($this->client)->postJson("$url/preview", $order)->assertForbidden();
        $this->actingAs($this->client)->patchJson("$url/reorder", $order)->assertForbidden();
        $this->actingAs($this->employee)->patchJson("$url/reorder", $order)->assertForbidden();
        $this->actingAs($this->admin)->patchJson("$url/reorder", $order)->assertForbidden();

        $this->actingAs($this->owner)->postJson("$url/preview", $order)->assertOk()
            ->assertJsonPath('fits', true)
            ->assertJsonPath('items.0.work_item_id', $b->id)
            ->assertJsonPath('items.0.planned_start', $this->mon->toDateString());
        $this->assertSame(1, $a->fresh()->queue_position, 'preview writes nothing');

        $this->actingAs($this->owner)->patchJson("$url/reorder", $order)->assertOk()
            ->assertJsonPath('up_next.0.work_item_id', $b->id)
            ->assertJsonPath('up_next.1.work_item_id', $a->id);
        $this->assertSame(1, $b->fresh()->queue_position);
        $this->assertSame(2, QueueEvent::query()->where('type', 'reordered')->count());
    }

    public function test_reorder_must_list_every_open_item_exactly_once(): void
    {
        $a = $this->item('First', 6);
        $b = $this->item('Second', 6);
        $url = "/api/agency/assignments/{$this->assignment->id}/queue/reorder";

        $this->actingAs($this->owner)->patchJson($url, ['ordered_work_item_ids' => [$a->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('ordered_work_item_ids');
        $this->patchJson($url, ['ordered_work_item_ids' => [$a->id, $a->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('ordered_work_item_ids.1');
        $this->patchJson($url, ['ordered_work_item_ids' => [$a->id, $b->id, 999999]])
            ->assertUnprocessable()->assertJsonValidationErrors('ordered_work_item_ids');
        $this->patchJson($url, [])->assertUnprocessable();
    }

    public function test_a_reorder_that_breaks_a_due_date_is_blocked_with_a_clear_message(): void
    {
        $a = $this->item('Tight', 12, 1);
        $b = $this->item('Loose', 6);

        $response = $this->actingAs($this->owner)
            ->patchJson("/api/agency/assignments/{$this->assignment->id}/queue/reorder", ['ordered_work_item_ids' => [$b->id, $a->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('ordered_work_item_ids');
        $this->assertStringContainsString('Tight', $response->json('errors.ordered_work_item_ids.0'));
        $this->assertSame(1, $a->fresh()->queue_position);
    }

    public function test_clients_see_queues_but_can_only_append_through_projects(): void
    {
        $this->item('Owner work', 6, 12, null, $this->owner);

        $this->actingAs($this->client)->getJson("/api/agency/assignments/{$this->assignment->id}/queue")
            ->assertOk()->assertJsonCount(1, 'up_next');

        $this->postJson('/api/agency/projects', [
            'title' => 'Client project', 'description' => 'More.', 'estimated_hours' => 6,
            'start_date' => $this->mon->toDateString(), 'due_date' => $this->mon->addDays(10)->toDateString(),
            'assignment_id' => $this->assignment->id,
        ])->assertCreated();

        $this->getJson("/api/agency/assignments/{$this->assignment->id}/queue")
            ->assertJsonPath('up_next.1.title', 'Client project')
            ->assertJsonPath('up_next.1.position', 2);
        $this->assertSame(1, QueueEvent::query()->where('type', 'added')->count());
    }

    public function test_employee_and_admin_have_read_only_views(): void
    {
        $this->item('Work', 6);
        $other = $this->makeEmployee('Other');

        $this->actingAs($this->employee)->getJson('/api/work-items/mine/queue')
            ->assertOk()->assertJsonPath('up_next.0.title', 'Work')->assertJsonPath('employee.id', $this->employee->id);
        $this->actingAs($other)->getJson('/api/work-items/mine/queue')->assertOk()->assertJsonCount(0, 'up_next');

        $this->actingAs($this->admin)->getJson("/api/admin/employees/{$this->employee->id}/queue")
            ->assertOk()->assertJsonPath('up_next.0.title', 'Work');
        $this->actingAs($this->admin)->getJson("/api/admin/employees/{$this->owner->id}/queue")->assertNotFound();
        $this->actingAs($this->owner)->getJson("/api/admin/employees/{$this->employee->id}/queue")->assertForbidden();
        $this->actingAs($this->client)->getJson('/api/work-items/mine/queue')->assertForbidden();
    }

    public function test_other_agencies_get_404_for_every_queue_endpoint(): void
    {
        $a = $this->item('Mine', 6);
        $other = $this->makeAgency('Rival');
        $rivalOwner = $this->makeOwner($other);
        $rivalClient = $this->makeClient($other);
        $url = "/api/agency/assignments/{$this->assignment->id}/queue";
        $order = ['ordered_work_item_ids' => [$a->id]];

        foreach ([$rivalOwner, $rivalClient] as $user) {
            $this->actingAs($user)->getJson($url)->assertNotFound();
        }
        $this->actingAs($rivalOwner)->postJson("$url/preview", $order)->assertNotFound();
        $this->actingAs($rivalOwner)->patchJson("$url/reorder", $order)->assertNotFound();
    }

    public function test_an_ended_assignment_has_no_queue(): void
    {
        $this->item('Work', 6);
        app(AssignmentService::class)->end($this->assignment, $this->owner);

        $this->actingAs($this->owner)->getJson("/api/agency/assignments/{$this->assignment->id}/queue")->assertNotFound();
    }

    public function test_reorder_is_rate_limited(): void
    {
        $a = $this->item('A', 6);
        $url = "/api/agency/assignments/{$this->assignment->id}/queue/reorder";
        $this->actingAs($this->owner);
        foreach (range(1, 20) as $i) {
            $this->patchJson($url, ['ordered_work_item_ids' => [$a->id]])->assertOk();
        }
        $this->patchJson($url, ['ordered_work_item_ids' => [$a->id]])->assertStatus(429);
    }
}
