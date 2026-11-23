<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\RushRequest;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\EmployeeQueuePlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/**
 * Two agencies, a pool employee and an admin. Every queue, rush request and notification response of agency A's
 * people is checked: none may reveal the platform, the admin side, the pool, or anything about agency B.
 * (Charges are admin-only; agency users have no route to them, which is asserted too.)
 */
class QueueLeakTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private User $ownerA;

    private User $clientA;

    private User $ownerB;

    private User $admin;

    private User $employeeA;

    private EmployeeAssignment $assignmentA;

    private EmployeeAssignment $assignmentB;

    private CarbonImmutable $mon;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scheduling.require_timeline_review' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-12-07 08:00', config('app.timezone')));
        $this->setUpWeek();
        $this->mon = $this->today;

        $a = Agency::create(['name' => 'Northwind Legal Entity Ltd', 'slug' => 'northwind']);
        $a->branding()->create(['display_name' => 'Northwind']);
        $b = Agency::create(['name' => 'Sunrise Rival Studio', 'slug' => 'sunrise-rival']);
        $b->branding()->create(['display_name' => 'Sunrise']);
        $this->ownerA = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $a->id, 'name' => 'Olive Owner']);
        $this->clientA = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $a->id, 'name' => 'Cara Client']);
        $this->ownerB = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $b->id, 'name' => 'Bruno Rival', 'email' => 'bruno@sunrise-rival.test']);
        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Ada Backoffice', 'email' => 'ada@platform.test']);
        $this->employeeA = $this->makeEmployee('Emma Alpha');
        $employeeB = $this->makeEmployee('Zed Rivalhire');
        $this->makeEmployee('Peter Hidden'); // nobody holds him
        $this->assignmentA = $this->hold($this->employeeA, $a, $this->ownerA);
        $this->assignmentB = $this->hold($employeeB, $b, $this->ownerB);

        $planner = app(EmployeeQueuePlanner::class);
        foreach ([[$this->assignmentA, $this->ownerA, 'Website'], [$this->assignmentA, $this->clientA, 'Brochure'], [$this->assignmentB, $this->ownerB, 'Sunrise secret project']] as [$assignment, $creator, $title]) {
            WorkItem::create([
                'agency_id' => $assignment->agency_id, 'client_id' => $creator->id, 'assignment_id' => $assignment->id, 'employee_id' => $assignment->employee_id,
                'title' => $title, 'description' => 'd', 'estimated_hours' => 6, 'start_date' => $this->mon->toDateString(),
                'due_date' => $this->mon->addDays(20)->toDateString(), 'status' => 'scheduled', 'queue_position' => $planner->nextPosition($assignment->employee),
            ]);
            $planner->replan($assignment->employee);
        }

        // Agency B has rush requests and a charge of its own.
        $rushB = $this->actingAs($this->ownerB)->postJson('/api/agency/rush-requests', [
            'type' => 'priority_insert', 'assignment_id' => $this->assignmentB->id, 'title' => 'Sunrise rush', 'description' => 'x', 'estimated_hours' => 2, 'needed_by' => $this->mon->addDays(3)->toDateString(),
        ])->assertCreated()->json('rush_request.id');
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$rushB/quote", ['amount_minor' => 77700])->assertOk();
        $this->actingAs($this->ownerB)->postJson("/api/agency/rush-requests/$rushB/accept")->assertOk();

        // Agency A: an accepted rush, a quoted extra-employee request naming a pool employee, and a rejected one.
        $accepted = $this->actingAs($this->clientA)->postJson('/api/agency/rush-requests', [
            'type' => 'priority_insert', 'assignment_id' => $this->assignmentA->id, 'title' => 'Fix checkout', 'description' => 'x', 'estimated_hours' => 3, 'needed_by' => $this->mon->addDays(3)->toDateString(),
        ])->json('rush_request.id');
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$accepted/quote", ['amount_minor' => 42000, 'note' => 'Priority slot'])->assertOk();
        $this->actingAs($this->ownerA)->postJson("/api/agency/rush-requests/$accepted/accept")->assertOk();

        $hidden = User::query()->where('name', 'Peter Hidden')->firstOrFail();
        $extra = $this->actingAs($this->ownerA)->postJson('/api/agency/rush-requests', [
            'type' => 'extra_employee', 'title' => 'More hands', 'description' => 'x', 'skills_notes' => 'Design', 'estimated_hours' => 6, 'needed_by' => $this->mon->addDays(5)->toDateString(),
        ])->json('rush_request.id');
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$extra/quote", ['amount_minor' => 15000, 'employee_id' => $hidden->id])->assertOk();

        $rejected = $this->actingAs($this->clientA)->postJson('/api/agency/rush-requests', [
            'type' => 'extra_employee', 'title' => 'Even more hands', 'description' => 'x', 'estimated_hours' => 6, 'needed_by' => $this->mon->addDays(5)->toDateString(),
        ])->json('rush_request.id');
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$rejected/reject", ['reason' => 'Ada Backoffice says Peter Hidden is in the shared pool.'])->assertOk();
    }

    /** @return list<string> */
    private function forbidden(): array
    {
        return [
            'ClientHub', 'Administrator', 'administrator', 'Shared employee', 'shared employee', 'shared pool', 'another agency', 'other agency', ' platform',
            'Sunrise', 'sunrise', 'Bruno Rival', 'bruno@sunrise-rival.test', 'Zed Rivalhire', 'secret project',
            'Ada Backoffice', 'ada@platform.test', 'Legal Entity', 'Peter Hidden', '"role":"admin"', '"actor_role":"admin"', '777', '77700',
        ];
    }

    private function assertClean(string $label, string $body, array $except = []): void
    {
        foreach (array_diff($this->forbidden(), $except) as $needle) {
            $this->assertStringNotContainsString($needle, $body, "{$label} leaks “{$needle}”");
        }
        $this->assertDoesNotMatchRegularExpression('/"agency_id":'.$this->ownerB->agency_id.'\b/', $body, "{$label} leaks another agency id");
        $this->assertDoesNotMatchRegularExpression('/\bpool\b/i', $body, "{$label} mentions the pool");
    }

    public function test_agency_a_queue_and_rush_responses_reveal_nothing_about_others(): void
    {
        $queue = "/api/agency/assignments/{$this->assignmentA->id}/queue";
        foreach ([$this->ownerA, $this->clientA] as $user) {
            $this->actingAs($user);
            $this->assertClean($user->name.' queue', $this->getJson($queue)->assertOk()->getContent());
            $list = $this->getJson('/api/agency/rush-requests')->assertOk();
            $this->assertClean($user->name.' rush list', $list->getContent());
            foreach ($list->json('rush_requests') as $row) {
                $this->assertClean($user->name.' rush #'.$row['id'], $this->getJson('/api/agency/rush-requests/'.$row['id'])->assertOk()->getContent());
            }
            $this->assertClean($user->name.' notifications', $this->getJson('/api/notifications')->assertOk()->getContent());
        }

        $this->actingAs($this->ownerA);
        $ids = collect($this->getJson($queue)->json('up_next'))->pluck('work_item_id')->reverse()->values()->all();
        $this->assertClean('reorder', $this->postJson("$queue/preview", ['ordered_work_item_ids' => $ids])->assertOk()->getContent());
        $this->assertClean('reorder', $this->patchJson("$queue/reorder", ['ordered_work_item_ids' => $ids])->assertOk()->getContent());
    }

    public function test_the_employee_view_and_notifications_are_clean_and_priceless(): void
    {
        $this->actingAs($this->employeeA);
        $queue = $this->getJson('/api/work-items/mine/queue')->assertOk();
        // Employees are platform staff: they know which agency they work for, so its legal name is not a leak here.
        $this->assertClean('employee queue', $queue->getContent(), ['Legal Entity']);
        $notifications = $this->getJson('/api/notifications')->assertOk()->getContent();
        $this->assertClean('employee notifications', $notifications, ['Legal Entity']);
        $this->assertStringContainsString('Fix checkout', $notifications, 'the employee is told about the urgent task');
        foreach ([$queue->getContent(), $notifications] as $body) {
            $this->assertStringNotContainsString('420.00', $body);
            $this->assertStringNotContainsString('42000', $body);
            $this->assertStringNotContainsString('USD', $body);
        }
    }

    public function test_the_extra_employee_request_never_names_the_pool_employee_in_any_agency_view(): void
    {
        $this->actingAs($this->ownerA);
        $body = $this->getJson('/api/agency/rush-requests')->getContent();
        $this->assertStringContainsString('More hands', $body);
        $this->assertStringContainsString('No team member is available for this request.', $body);
        $this->assertStringNotContainsString('Peter', $body);
        $this->assertStringNotContainsString('proposed_employee', $body);
        $this->assertClean('owner notifications', $this->getJson('/api/notifications')->getContent());
    }

    public function test_agency_a_cannot_reach_agency_b_queue_rush_or_charges(): void
    {
        $this->actingAs($this->ownerA);
        $this->getJson("/api/agency/assignments/{$this->assignmentB->id}/queue")->assertNotFound();
        $this->patchJson("/api/agency/assignments/{$this->assignmentB->id}/queue/reorder", ['ordered_work_item_ids' => [1]])->assertNotFound();
        $rushB = RushRequest::query()->where('title', 'Sunrise rush')->firstOrFail();
        $this->getJson("/api/agency/rush-requests/{$rushB->id}")->assertNotFound();
        $this->postJson("/api/agency/rush-requests/{$rushB->id}/accept")->assertNotFound();
        $this->getJson('/api/admin/charges')->assertForbidden();
        $this->getJson('/api/admin/rush-requests')->assertForbidden();
        $this->getJson("/api/admin/employees/{$this->assignmentB->employee_id}/queue")->assertForbidden();
    }
}
