<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\TimelineNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/** Agency owners and clients submit requests; only an admin plans the days. Nothing is scheduled on their behalf. */
class AdminOnlySchedulingTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private $agency;

    private User $owner;

    private User $client;

    private User $employee;

    private User $admin;

    private EmployeeAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scheduling.admin_only_scheduling' => true, 'scheduling.require_timeline_review' => true]);
        $this->setUpWeek();
        $this->agency = $this->makeAgency('Northwind');
        $this->owner = $this->makeOwner($this->agency, 'Olive Owner');
        $this->client = $this->makeClient($this->agency, 'Cara Client');
        $this->employee = $this->makeEmployee('Ed Employee');
        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Ada Admin', 'is_active' => true]);
        $this->assignment = $this->hold($this->employee, $this->agency, $this->owner);
    }

    private function mon(): CarbonImmutable
    {
        return $this->monday->addDays(7);
    }

    private function submit(User $as, array $overrides = [])
    {
        return $this->actingAs($as)->postJson('/api/agency/projects', ['title' => 'Website build', 'description' => 'Build it.', 'assignment_id' => $this->assignment->id, ...$overrides]);
    }

    private function day(CarbonImmutable $date, float $hours): array
    {
        return ['date' => $date->toDateString(), 'hours' => $hours];
    }

    public function test_a_submitted_request_holds_no_hours_and_ignores_any_dates_sent(): void
    {
        Notification::fake();
        $sentStart = $this->mon()->toDateString();
        $sentDue = $this->mon()->addDays(3)->toDateString();
        $id = $this->submit($this->owner, ['estimated_hours' => 5, 'start_date' => $sentStart, 'due_date' => $sentDue])->assertCreated()
            ->assertJsonPath('project.schedule_state', 'awaiting')->assertJsonPath('project.timeline_status', 'proposed')->json('project.id');
        $project = WorkItem::findOrFail($id);

        $this->assertTrue($project->awaiting_schedule);
        $this->assertSame(0, DB::table('work_item_allocations')->where('work_item_id', $id)->count(), 'nothing is auto-scheduled');
        $this->assertNotSame($sentDue, $project->due_date->toDateString(), 'the dates the agency sent are never stored');
        $this->assertSame('5.00', $project->estimated_hours);

        // The estimate is optional.
        $this->submit($this->client)->assertCreated()->assertJsonPath('project.estimated_hours', '0.00');

        // A second submission does not schedule the first either.
        $this->assertSame(0, DB::table('work_item_allocations')->count());

        Notification::assertSentTo($this->owner, TimelineNotice::class, fn ($n) => str_starts_with($n->payload['title'], 'Request sent for review') && str_contains($n->payload['body'], 'Your request has been sent for review') && ! str_contains($n->payload['body'], 'Ada Admin'));
        Notification::assertSentTo($this->admin, TimelineNotice::class, fn ($n) => str_contains($n->payload['title'], 'Timeline review needed'));
    }

    public function test_a_client_submission_gives_the_owner_a_neutral_notice_once(): void
    {
        Notification::fake();
        $this->submit($this->client, ['estimated_hours' => 4])->assertCreated();

        $forOwner = Notification::sent($this->owner, TimelineNotice::class);
        $this->assertCount(1, $forOwner);
        $this->assertStringContainsString('has been sent for review by Cara Client', $forOwner->first()->payload['body']);
        $this->assertStringNotContainsString('Ada Admin', $forOwner->first()->payload['body']);
        Notification::assertSentToTimes($this->client, TimelineNotice::class, 1);
    }

    public function test_tickets_can_be_added_while_waiting_and_get_dates_once_the_admin_plans(): void
    {
        $project = WorkItem::findOrFail($this->submit($this->owner, ['estimated_hours' => 10])->assertCreated()->json('project.id'));
        $ticketId = $this->actingAs($this->owner)->postJson("/api/agency/projects/{$project->id}/tickets", ['title' => 'Header', 'description' => 'd', 'estimated_hours' => 4])->assertCreated()->json('ticket.id');
        $this->assertNull(Ticket::find($ticketId)->start_date);

        $mon = $this->mon();
        $this->actingAs($this->admin)->patchJson("/api/admin/projects/{$project->id}/timeline", ['days' => [$this->day($mon, 6), $this->day($mon->addDay(), 4)], 'due_date' => $mon->addDays(4)->toDateString()])->assertOk();

        $project->refresh();
        $this->assertFalse($project->awaiting_schedule);
        $this->assertSame('10.00', $project->estimated_hours);
        $this->assertSame($mon->toDateString(), Ticket::find($ticketId)->start_date->toDateString());
    }

    public function test_agency_edits_cannot_change_dates_and_a_new_estimate_goes_back_to_the_admin(): void
    {
        $project = WorkItem::findOrFail($this->submit($this->owner, ['estimated_hours' => 5])->assertCreated()->json('project.id'));
        $placeholderDue = $project->due_date->toDateString();
        $mon = $this->mon();

        // While waiting: dates in the payload are ignored, the estimate and the employee preference can change.
        $this->actingAs($this->owner)->patchJson("/api/agency/projects/{$project->id}", ['due_date' => $mon->addDays(30)->toDateString(), 'start_date' => $mon->toDateString(), 'estimated_hours' => 6])->assertOk();
        $this->assertSame($placeholderDue, $project->fresh()->due_date->toDateString());
        $this->assertSame('6.00', $project->fresh()->estimated_hours);

        // The admin plans and confirms.
        $this->actingAs($this->admin)->patchJson("/api/admin/projects/{$project->id}/timeline", ['days' => [$this->day($mon, 6), $this->day($mon->addDay(), 2)], 'due_date' => $mon->addDays(4)->toDateString()])->assertOk();
        $this->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertOk();
        $before = DB::table('work_item_allocations')->where('work_item_id', $project->id)->orderBy('work_date')->get(['work_date', 'hours'])->toArray();
        $confirmedStart = $project->fresh()->start_date->toDateString();

        // Details after confirmation leave the schedule alone.
        $this->actingAs($this->owner)->patchJson("/api/agency/projects/{$project->id}", ['title' => 'Website build v2', 'description' => 'Changed.'])->assertOk();
        $this->assertSame('confirmed', $project->fresh()->timeline_status);
        $this->assertEquals($before, DB::table('work_item_allocations')->where('work_item_id', $project->id)->orderBy('work_date')->get(['work_date', 'hours'])->toArray());

        // A new estimate is kept as the request and flagged for the admin; the days stay as planned.
        $this->patchJson("/api/agency/projects/{$project->id}", ['estimated_hours' => 12, 'due_date' => $mon->addDays(60)->toDateString()])->assertOk();
        $fresh = $project->fresh();
        $this->assertSame('12.00', $fresh->estimated_hours);
        $this->assertSame('8.00', $fresh->planned_hours);
        $this->assertSame('proposed', $fresh->timeline_status);
        $this->assertSame($confirmedStart, $fresh->start_date->toDateString());
        $this->assertEquals($before, DB::table('work_item_allocations')->where('work_item_id', $project->id)->orderBy('work_date')->get(['work_date', 'hours'])->toArray());

        // Once days exist, the employee is the admin's to change.
        $other = $this->hold($this->makeEmployee('Eve Employee'), $this->agency, $this->owner);
        $this->patchJson("/api/agency/projects/{$project->id}", ['assignment_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('assignment_id');
    }

    public function test_the_employee_preference_can_change_while_waiting(): void
    {
        $project = WorkItem::findOrFail($this->submit($this->owner)->assertCreated()->json('project.id'));
        $other = $this->hold($this->makeEmployee('Eve Employee'), $this->agency, $this->owner);

        $this->actingAs($this->owner)->patchJson("/api/agency/projects/{$project->id}", ['assignment_id' => $other->id])->assertOk();
        $this->assertSame($other->employee_id, $project->fresh()->employee_id);
    }

    public function test_only_an_admin_or_the_assigned_employee_writes_the_schedule(): void
    {
        $project = WorkItem::findOrFail($this->submit($this->client, ['estimated_hours' => 5])->assertCreated()->json('project.id'));
        $days = ['days' => [$this->day($this->mon(), 5)]];

        foreach ([$this->owner, $this->client] as $user) {
            $this->actingAs($user)->patchJson("/api/admin/projects/{$project->id}/timeline", $days)->assertForbidden();
            $this->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertForbidden();
            $this->assertSame(405, $this->patchJson("/api/agency/projects/{$project->id}/timeline", $days)->status());
        }
        $this->assertSame(0, DB::table('work_item_allocations')->where('work_item_id', $project->id)->count());
    }

    public function test_urgent_requests_lead_the_admin_review_queue(): void
    {
        $first = $this->submit($this->owner, ['title' => 'Older normal request'])->assertCreated()->json('project.id');
        $this->travel(1)->hours();
        $urgent = $this->submit($this->client, ['title' => 'Newer urgent request', 'brief_priority' => 'urgent'])->assertCreated()->json('project.id');

        $reviews = $this->actingAs($this->admin)->getJson('/api/admin/timeline-reviews')->assertOk()->json('reviews');
        $this->assertSame([$urgent, $first], array_column($reviews, 'id'));
        $this->assertTrue($reviews[0]['priority_urgent']);
        $this->assertTrue($reviews[0]['awaiting_schedule']);
    }

    public function test_the_confirmation_tells_the_agency_the_planned_hours_and_never_who_planned_them(): void
    {
        Notification::fake();
        $project = WorkItem::findOrFail($this->submit($this->client, ['estimated_hours' => 5])->assertCreated()->json('project.id'));
        $mon = $this->mon();
        $this->actingAs($this->admin)->patchJson("/api/admin/projects/{$project->id}/timeline", ['days' => [$this->day($mon, 5), $this->day($mon->addDay(), 3)], 'due_date' => $mon->addDays(4)->toDateString(), 'note' => 'Needs more time.'])->assertOk();
        $this->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertOk();

        foreach ([$this->client, $this->owner] as $user) {
            $mine = Notification::sent($user, TimelineNotice::class)->filter(fn ($n) => str_starts_with($n->payload['title'], 'Confirmed:'));
            $this->assertCount(1, $mine);
            $body = $mine->first()->payload['body'];
            $this->assertStringContainsString('Your timeline has been confirmed', $body);
            $this->assertStringContainsString('8 h (you proposed 5 h)', $body);
            $this->assertStringNotContainsString('Ada Admin', $body);
        }
        $this->actingAs($this->owner)->getJson("/api/agency/projects/{$project->id}")->assertOk()->assertJsonPath('project.schedule_state', 'confirmed')->assertJsonPath('project.planned_hours', 8);
    }

    public function test_the_hidden_sidebar_items_are_blocked_for_the_client_in_the_api(): void
    {
        $this->actingAs($this->client);
        $this->getJson('/api/agency/clients')->assertForbidden();
        $this->getJson('/api/agency/time-summary')->assertForbidden();   // agency-wide totals, so not offered to a client
        $this->putJson('/api/agency/branding', ['display_name' => 'Hacked'])->assertForbidden();
        $this->getJson('/api/agency/branding')->assertOk();               // read-only, the portal theme needs it

        $this->actingAs($this->owner)->getJson('/api/agency/clients')->assertOk();
        $this->assertNotSame(403, $this->getJson('/api/agency/time-summary')->status());
    }
}