<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\TimelineNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/** The timeline review workflow: proposed schedules reserve capacity, admins review, confirmation unlocks work. */
class TimelineReviewTest extends TestCase
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
        config(['scheduling.require_timeline_review' => true]);
        $this->setUpWeek();
        $this->agency = $this->makeAgency('Northwind');
        $this->owner = $this->makeOwner($this->agency);
        $this->client = $this->makeClient($this->agency, 'Cara Client');
        $this->employee = $this->makeEmployee('Ed Employee');
        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Ada Admin', 'is_active' => true]);
        $this->assignment = $this->hold($this->employee, $this->agency, $this->owner);
    }

    private function monday(): CarbonImmutable
    {
        return $this->monday->addDays(7);
    }

    private function create(array $overrides = [], ?User $as = null): WorkItem
    {
        $payload = ['title' => 'Website build', 'description' => 'Build the site. Notes: use the brand colours.', 'estimated_hours' => 12, 'start_date' => $this->monday()->toDateString(), 'due_date' => $this->monday()->addDays(3)->toDateString(), 'assignment_id' => $this->assignment->id, ...$overrides];
        $id = $this->actingAs($as ?? $this->owner)->postJson('/api/agency/projects', $payload)->assertCreated()->json('project.id');

        return WorkItem::findOrFail($id);
    }

    private function days(WorkItem $project): array
    {
        return $project->allocations()->orderBy('work_date')->get()->map(fn ($a) => ['date' => CarbonImmutable::parse($a->work_date)->toDateString(), 'hours' => (float) $a->hours])->all();
    }

    private function ticket(WorkItem $project, float $hours = 3, string $title = 'Ticket'): Ticket
    {
        $id = $this->actingAs($this->owner)->postJson("/api/agency/projects/{$project->id}/tickets", ['title' => $title, 'description' => 'd', 'estimated_hours' => $hours])->assertCreated()->json('ticket.id');

        return Ticket::findOrFail($id);
    }

    public function test_a_new_project_is_proposed_reserves_capacity_and_tells_everyone(): void
    {
        Notification::fake();
        $second = User::factory()->create(['role' => Role::Admin, 'is_active' => true]);

        $project = $this->create();

        $this->assertSame('proposed', $project->timeline_status);
        $this->assertNull($project->project_manager_id);
        $this->assertNotNull($project->timeline_review_due_at);
        $this->assertSame(1, TimelineEvent::where('work_item_id', $project->id)->where('type', 'proposed')->count());
        $this->assertSame(12.0, array_sum(array_column($this->days($project), 'hours')));

        Notification::assertSentTo([$this->admin, $second], TimelineNotice::class, function ($n) use ($project) {
            return str_contains($n->payload['title'], 'Timeline review needed')
                && str_contains($n->payload['body'], 'Northwind') && str_contains($n->payload['body'], 'Ed Employee') && str_contains($n->payload['body'], '12 h')
                && str_contains($n->payload['body'], 'Proposed days') && str_contains($n->payload['body'], 'brand colours')
                && $n->payload['url_path'] === "/admin/timeline-reviews/{$project->id}";
        });
        Notification::assertSentTo($this->employee, TimelineNotice::class, fn ($n) => str_contains($n->payload['title'], "You've been assigned") && str_contains($n->payload['body'], 'pending review'));
        Notification::assertSentTo($this->owner, TimelineNotice::class, fn ($n) => str_contains($n->payload['title'], 'Received') && $n->payload['url_path'] === "/agency/projects/{$project->id}");
        Notification::assertNotSentTo($this->client, TimelineNotice::class);        // the creator is told, bystanders are not
    }

    public function test_proposed_hours_reserve_the_employees_capacity(): void
    {
        $this->create(['estimated_hours' => 24, 'due_date' => $this->monday()->addDays(3)->toDateString()]);      // Mon to Thu, 6 h a day: full

        $this->actingAs($this->owner)->postJson('/api/agency/projects', ['title' => 'Second', 'description' => 'd', 'estimated_hours' => 6, 'start_date' => $this->monday()->toDateString(), 'due_date' => $this->monday()->addDays(3)->toDateString(), 'assignment_id' => $this->assignment->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assignment_id');
    }

    public function test_the_first_admin_to_open_the_review_becomes_the_project_manager(): void
    {
        $project = $this->create();
        $other = User::factory()->create(['role' => Role::Admin, 'is_active' => true]);

        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$project->id}/timeline/start-review")->assertOk()
            ->assertJsonPath('timeline.status', 'in_review')->assertJsonPath('timeline.project_manager.name', 'Ada Admin');
        $this->actingAs($other)->postJson("/api/admin/projects/{$project->id}/timeline/start-review")->assertOk()->assertJsonPath('timeline.project_manager.name', 'Ada Admin');
        $this->assertSame(1, TimelineEvent::where('type', 'review_started')->count());
    }

    public function test_editing_days_enforces_every_scheduling_rule_with_errors_per_day(): void
    {
        $project = $this->create();
        $mon = $this->monday();
        $url = "/api/admin/projects/{$project->id}/timeline";
        $day = fn (CarbonImmutable $d, $h) => ['date' => $d->toDateString(), 'hours' => $h];

        // 7 h in a day
        $this->actingAs($this->admin)->patchJson($url, ['days' => [$day($mon, 7), $day($mon->addDay(), 5)]])->assertUnprocessable()->assertJsonValidationErrors(['days.0.hours']);
        // Sunday
        $this->patchJson($url, ['days' => [$day($mon->addDays(6), 6), $day($mon, 6)]])->assertUnprocessable()->assertJsonPath('errors.days\.0\.date.0', null);
        // (the nested key form)
        $r = $this->patchJson($url, ['days' => [$day($mon->addDays(6), 6), $day($mon, 6)]])->assertUnprocessable();
        $this->assertSame('Sundays cannot hold work.', $r->json('errors')['days.0.date'][0]);
        // after the due date
        $r = $this->patchJson($url, ['days' => [$day($mon, 6), $day($mon->addDays(5), 6)]])->assertUnprocessable();
        $this->assertStringContainsString('after the due date', $r->json('errors')['days.1.date'][0]);
        // in the past
        $r = $this->patchJson($url, ['days' => [$day($mon->subDays(30), 6), $day($mon, 6)]])->assertUnprocessable();
        $this->assertSame('This date is in the past.', $r->json('errors')['days.0.date'][0]);
        // total must equal the project hours
        $r = $this->patchJson($url, ['days' => [$day($mon, 6), $day($mon->addDay(), 5)]])->assertUnprocessable();
        $this->assertStringContainsString('They must match', $r->json('errors.days.0'));
        // duplicate date, bad hours
        $this->patchJson($url, ['days' => [$day($mon, 6), $day($mon, 6)]])->assertUnprocessable()->assertJsonValidationErrors('days.1.date');
        $this->patchJson($url, ['days' => [$day($mon, 0), $day($mon->addDay(), 12)]])->assertUnprocessable()->assertJsonValidationErrors(['days.0.hours']);

        // a valid rearrangement is saved and logged with before and after
        $this->patchJson($url, ['days' => [$day($mon->addDay(), 6), $day($mon->addDays(3), 6)], 'note' => 'Client is away Monday.'])->assertOk()->assertJsonPath('project.start_date', $mon->addDay()->toDateString());
        $this->assertSame([$mon->addDay()->toDateString(), $mon->addDays(3)->toDateString()], array_column($this->days($project->fresh()), 'date'));
        $event = TimelineEvent::where('type', 'edited')->firstOrFail();
        $this->assertSame('Client is away Monday.', $event->note);
        $this->assertSame([$mon->toDateString(), $mon->addDay()->toDateString()], array_column($event->before['days'], 'date'));
    }

    public function test_the_weekly_limit_and_other_bookings_are_enforced(): void
    {
        $project = $this->create(['estimated_hours' => 12]);
        $mon = $this->monday();
        // another project already books the employee 5 h on Tuesday, and 30 h across the week
        $other = $this->book($this->assignment, 'in_progress', [[$mon->addDay(), 5], [$mon->addDays(2), 6], [$mon->addDays(3), 6], [$mon->addDays(4), 6], [$mon->addDays(5), 6], [$mon, 1]]);
        $url = "/api/admin/projects/{$project->id}/timeline";

        $r = $this->actingAs($this->admin)->patchJson($url, ['days' => [['date' => $mon->addDay()->toDateString(), 'hours' => 6], ['date' => $mon->toDateString(), 'hours' => 6]]])->assertUnprocessable();
        $this->assertStringContainsString('Only 1.00 h are free', $r->json('errors')['days.0.hours'][0]);
        // 30 h elsewhere in that week + 12 h > 36 h
        $r = $this->patchJson($url, ['days' => [['date' => $mon->toDateString(), 'hours' => 5], ['date' => $mon->addDays(2)->toDateString(), 0.0001]]]);
        $r->assertUnprocessable();
        $this->assertNotNull($other);
    }

    public function test_the_due_date_can_be_moved_by_the_admin(): void
    {
        $project = $this->create();
        $late = $this->monday()->addDays(9);

        $this->actingAs($this->admin)->patchJson("/api/admin/projects/{$project->id}/timeline", [
            'days' => [['date' => $this->monday()->addDays(7)->toDateString(), 'hours' => 6], ['date' => $late->toDateString(), 'hours' => 6]], 'due_date' => $late->toDateString(), 'note' => 'More realistic.',
        ])->assertOk()->assertJsonPath('project.due_date', $late->toDateString());
        $this->assertSame(1, TimelineEvent::where('type', 'due_date_changed')->count());
    }

    public function test_changes_requested_then_the_owner_replies_and_it_goes_back_to_proposed(): void
    {
        Notification::fake();
        $project = $this->create();
        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$project->id}/timeline/start-review")->assertOk();

        $this->postJson("/api/admin/projects/{$project->id}/timeline/request-changes", ['message' => ''])->assertUnprocessable();
        $this->postJson("/api/admin/projects/{$project->id}/timeline/request-changes", ['message' => 'Please split this into two phases.'])->assertOk()->assertJsonPath('timeline.status', 'changes_requested');
        Notification::assertSentTo($this->owner, TimelineNotice::class, fn ($n) => str_contains($n->payload['body'], 'split this into two phases') && $n->payload['action_text'] === 'Reply to your project manager');
        $this->assertTrue($this->actingAs($this->owner)->getJson("/api/agency/projects/{$project->id}/timeline")->assertOk()->json('timeline.can_respond'));
        $this->assertSame('changes_requested', collect($this->getJson("/api/agency/projects/{$project->id}/timeline")->json('events'))->first()['type']);

        $this->postJson("/api/agency/projects/{$project->id}/timeline/respond", ['note' => 'Done: phase one is the 12 h.'])->assertOk()->assertJsonPath('timeline.status', 'proposed');
        Notification::assertSentTo($this->admin, TimelineNotice::class, fn ($n) => str_contains($n->payload['title'], 'Reply received') && str_contains($n->payload['body'], 'phase one'));
        $this->postJson("/api/agency/projects/{$project->id}/timeline/respond", ['note' => 'again'])->assertUnprocessable();      // nothing waiting any more
    }

    public function test_confirming_unlocks_work_and_tells_everyone(): void
    {
        $project = $this->create();
        $ticket = $this->ticket($project, 3);
        $this->assertSame('proposed', $ticket->timeline_status);

        // Before confirmation the employee cannot start.
        $this->actingAs($this->employee)->postJson("/api/tickets/{$ticket->id}/transitions", ['action' => 'start'])->assertUnprocessable()->assertJsonPath('message', "This timeline hasn't been confirmed yet.");
        $this->assertSame('todo', $ticket->fresh()->status);

        Notification::fake();
        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertOk()
            ->assertJsonPath('timeline.status', 'confirmed')->assertJsonPath('timeline.confirmed_by', 'Ada Admin')->assertJsonPath('timeline.project_manager.name', 'Ada Admin');
        $this->assertSame('confirmed', $ticket->fresh()->timeline_status);
        Notification::assertSentTo([$this->owner, $this->employee, $this->admin], TimelineNotice::class, fn ($n) => str_contains($n->payload['title'], 'Timeline confirmed') && str_contains($n->payload['body'], 'Proposed days'));

        $this->actingAs($this->employee)->postJson("/api/tickets/{$ticket->id}/transitions", ['action' => 'start'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertUnprocessable();    // nothing left to confirm
    }

    public function test_the_assigned_employee_can_review_edit_and_confirm_only_their_own_work(): void
    {
        $project = $this->create();
        $rival = $this->makeEmployee('Rita Rival');

        $this->actingAs($this->employee)->getJson("/api/work-items/{$project->id}/timeline")->assertOk()->assertJsonPath('timeline.can_edit', true);
        $this->getJson('/api/work-items/awaiting-timeline')->assertOk()->assertJsonCount(1, 'awaiting');
        $this->actingAs($rival)->getJson("/api/work-items/{$project->id}/timeline")->assertNotFound();
        $this->actingAs($rival)->postJson("/api/work-items/{$project->id}/timeline/confirm")->assertNotFound();
        $this->actingAs($rival)->patchJson("/api/work-items/{$project->id}/timeline", ['days' => [['date' => $this->monday()->toDateString(), 'hours' => 6]]])->assertNotFound();

        $this->actingAs($this->employee)->patchJson("/api/work-items/{$project->id}/timeline", ['days' => [['date' => $this->monday()->addDay()->toDateString(), 'hours' => 6], ['date' => $this->monday()->addDays(2)->toDateString(), 'hours' => 6]]])->assertOk();
        $this->postJson("/api/work-items/{$project->id}/timeline/confirm")->assertOk()->assertJsonPath('timeline.status', 'confirmed');
        $this->assertNull($project->fresh()->project_manager_id);                       // an employee confirming does not make anyone project manager
        $this->getJson('/api/work-items/awaiting-timeline')->assertJsonCount(0, 'awaiting');

        // An ended assignment closes the door.
        $other = $this->create(['title' => 'Other', 'estimated_hours' => 6, 'start_date' => $this->monday()->addDays(14)->toDateString(), 'due_date' => $this->monday()->addDays(15)->toDateString()]);
        $this->assignment->update(['status' => 'ended', 'active_employee_id' => null]);
        $this->actingAs($this->employee)->getJson("/api/work-items/{$other->id}/timeline")->assertNotFound();
    }

    public function test_an_employee_cannot_confirm_while_changes_are_requested(): void
    {
        $project = $this->create();
        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$project->id}/timeline/request-changes", ['message' => 'Needs a rethink.'])->assertOk();

        $this->actingAs($this->employee)->postJson("/api/work-items/{$project->id}/timeline/confirm")->assertUnprocessable();
        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertOk();                  // the admin still can
    }

    public function test_a_ticket_added_to_a_confirmed_project_is_proposed_on_its_own(): void
    {
        $project = $this->create();
        $first = $this->ticket($project, 3, 'First');
        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertOk();
        Notification::fake();

        $second = $this->ticket($project->fresh(), 2, 'Second');

        $this->assertSame('confirmed', $project->fresh()->timeline_status);
        $this->assertSame('confirmed', $first->fresh()->timeline_status);
        $this->assertSame('proposed', $second->fresh()->timeline_status);
        Notification::assertSentTo($this->admin, TimelineNotice::class, fn ($n) => str_contains($n->payload['title'], 'Second'));
        // The confirmed ticket can be worked, the new one cannot yet.
        $this->actingAs($this->employee)->postJson("/api/tickets/{$first->id}/transitions", ['action' => 'start'])->assertOk();
        $this->postJson("/api/tickets/{$second->id}/transitions", ['action' => 'start'])->assertUnprocessable();
        // It shows up in the queue, and confirming clears only it.
        $this->actingAs($this->admin)->getJson('/api/admin/timeline-reviews')->assertJsonPath('reviews.0.pending_tickets', 1);
        $this->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertOk();
        $this->assertSame('confirmed', $second->fresh()->timeline_status);
        $this->getJson('/api/admin/timeline-reviews')->assertJsonCount(0, 'reviews');
    }

    public function test_editing_a_confirmed_project_reopens_it(): void
    {
        $project = $this->create();
        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertOk();

        $this->actingAs($this->owner)->patchJson("/api/agency/projects/{$project->id}", ['estimated_hours' => 6, 'due_date' => $this->monday()->addDays(2)->toDateString()])->assertOk();

        $this->assertSame('proposed', $project->fresh()->timeline_status);
        $this->assertSame(1, TimelineEvent::where('type', 'reopened')->count());
    }

    public function test_the_queue_is_oldest_first_filtered_and_flags_overdue_and_urgent_reviews(): void
    {
        $old = $this->create(['title' => 'Old one', 'estimated_hours' => 6, 'start_date' => $this->monday()->toDateString(), 'due_date' => $this->monday()->addDay()->toDateString()]);
        $old->forceFill(['timeline_proposed_at' => now()->subHours(30)])->save();
        $fresh = $this->create(['title' => 'Fresh one', 'estimated_hours' => 6, 'start_date' => $this->monday()->addDays(14)->toDateString(), 'due_date' => $this->monday()->addDays(15)->toDateString()]);

        $json = $this->actingAs($this->admin)->getJson('/api/admin/timeline-reviews')->assertOk();
        $this->assertSame(['Old one', 'Fresh one'], array_column($json->json('reviews'), 'title'));
        $this->assertTrue($json->json('reviews.0.overdue'));
        $this->assertFalse($json->json('reviews.1.overdue'));
        $this->assertSame(['pending' => 2, 'overdue' => 1], $this->getJson('/api/admin/timeline-reviews/count')->json());
        $this->getJson('/api/admin/timeline-reviews?search=fresh')->assertJsonCount(1, 'reviews');
        $this->getJson('/api/admin/timeline-reviews?status=changes_requested')->assertJsonCount(0, 'reviews');
        $this->getJson("/api/admin/timeline-reviews?agency={$this->agency->id}")->assertJsonCount(2, 'reviews');

        // urgent: the start date is within 24 h and the timeline is still unconfirmed
        $fresh->forceFill(['start_date' => now(config('app.timezone'))->toDateString()])->save();
        $this->assertTrue(collect($this->getJson('/api/admin/timeline-reviews')->json('reviews'))->firstWhere('title', 'Fresh one')['urgent']);
        $this->assertNotNull($json->json('summary.changes_requested_rate') ?? 0);
    }

    public function test_reminders_go_out_once_at_24_and_48_hours(): void
    {
        Notification::fake();
        $project = $this->create();
        $project->forceFill(['timeline_proposed_at' => now()->subHours(25)])->save();

        $this->artisan('timeline:remind')->assertSuccessful();
        Notification::assertSentTo($this->admin, TimelineNotice::class, fn ($n) => str_contains($n->payload['title'], 'Review overdue (25 h)'));
        $this->artisan('timeline:remind')->assertSuccessful();                                      // not twice
        Notification::assertSentToTimes($this->admin, TimelineNotice::class, 2);                    // the proposal + one reminder

        $project->forceFill(['timeline_proposed_at' => now()->subHours(49)])->save();
        TimelineEvent::where('type', 'reminder_sent')->update(['created_at' => now()->subHours(1)]);
        $this->artisan('timeline:remind')->assertSuccessful();
        Notification::assertSentTo($this->admin, TimelineNotice::class, fn ($n) => str_contains($n->payload['title'], 'Review overdue (49 h)'));
        $this->assertSame(2, TimelineEvent::where('type', 'reminder_sent')->count());
        $this->assertSame(['reminder:24', 'reminder:48'], TimelineEvent::where('type', 'reminder_sent')->orderBy('id')->pluck('note')->all());
    }

    public function test_ending_the_assignment_releases_the_timeline_and_tells_admins_and_owners(): void
    {
        $project = $this->create();
        Notification::fake();

        $this->actingAs($this->owner)->deleteJson("/api/agency/assignments/{$this->assignment->id}")->assertOk();

        $this->assertSame('cancelled', $project->fresh()->status);
        $this->assertSame(0, DB::table('work_item_allocations')->where('work_item_id', $project->id)->count());
        Notification::assertSentTo([$this->admin, $this->owner], TimelineNotice::class, fn ($n) => str_contains($n->payload['title'], 'Timeline released'));
        $this->actingAs($this->admin)->getJson('/api/admin/timeline-reviews')->assertJsonCount(0, 'reviews');
    }

    public function test_access_is_tenant_scoped_and_role_checked(): void
    {
        $project = $this->create();
        $rivalAgency = $this->makeAgency('Rival');
        $rivalOwner = $this->makeOwner($rivalAgency, 'Rival Owner');
        $otherClient = $this->makeClient($this->agency, 'Other Client');

        // another agency: 404 everywhere
        $this->actingAs($rivalOwner)->getJson("/api/agency/projects/{$project->id}/timeline")->assertNotFound();
        $this->postJson("/api/agency/projects/{$project->id}/timeline/respond", [])->assertNotFound();
        // a client sees only projects they created
        $this->actingAs($otherClient)->getJson("/api/agency/projects/{$project->id}/timeline")->assertNotFound();
        // agency users never reach admin or employee routes
        foreach ([$this->owner, $this->client] as $user) {
            $this->actingAs($user)->getJson('/api/admin/timeline-reviews')->assertForbidden();
            $this->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertForbidden();
            $this->getJson("/api/work-items/{$project->id}/timeline")->assertForbidden();
        }
        // employees never reach admin routes, admins never the employee ones
        $this->actingAs($this->employee)->getJson('/api/admin/timeline-reviews')->assertForbidden();
        $this->actingAs($this->admin)->getJson("/api/work-items/{$project->id}/timeline")->assertForbidden();
        // the owner reads the thread read-only and cannot confirm or request changes
        $this->actingAs($this->owner)->getJson("/api/agency/projects/{$project->id}/timeline")->assertOk()->assertJsonPath('timeline.can_confirm', false)->assertJsonPath('timeline.can_request_changes', false);
        // the creator client sees their own
        $mine = $this->create(['title' => 'Cara project', 'estimated_hours' => 6, 'start_date' => $this->monday()->addDays(14)->toDateString(), 'due_date' => $this->monday()->addDays(15)->toDateString()], $this->client);
        $this->actingAs($this->client)->getJson("/api/agency/projects/{$mine->id}/timeline")->assertOk();
        auth()->forgetGuards();
        $this->getJson("/api/agency/projects/{$project->id}/timeline")->assertUnauthorized();
    }

    public function test_the_emails_have_a_subject_a_link_and_only_that_projects_facts(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.example.test']);
        $rivalAgency = $this->makeAgency('Rival Co');
        $rivalOwner = $this->makeOwner($rivalAgency, 'Rival Owner');
        $project = $this->create();

        $mails = [];
        foreach ([$this->admin, $this->employee, $this->owner] as $user) {
            Notification::assertSentTo($user, TimelineNotice::class, function ($n) use ($user, &$mails): bool {
                $mails[$user->role->value] = $n->toMail($user);

                return true;
            });
        }

        $admin = $mails['admin'];
        $this->assertSame('[ClientHub] Timeline review needed: Website build', $admin->subject);
        $this->assertSame("https://app.example.test/admin/timeline-reviews/{$project->id}", $admin->actionUrl);
        $this->assertSame('Review timeline', $admin->actionText);
        $this->assertSame("https://app.example.test/my-work/timeline/{$project->id}", $mails['employee']->actionUrl);
        $this->assertSame("https://app.example.test/agency/projects/{$project->id}", $mails['agency_owner']->actionUrl);
        foreach ($mails as $mail) {
            $text = implode(' ', $mail->introLines);
            $this->assertStringNotContainsString('Rival', $text);
            $this->assertStringNotContainsString('internal', strtolower($text));
        }
        $this->assertStringContainsString('Northwind', implode(' ', $admin->introLines));
        $this->assertNotNull($rivalOwner);
    }

    public function test_the_review_can_be_switched_off(): void
    {
        config(['scheduling.require_timeline_review' => false]);

        $project = $this->create();
        $ticket = $this->ticket($project, 2);

        $this->assertSame('confirmed', $project->timeline_status);
        $this->actingAs($this->employee)->postJson("/api/tickets/{$ticket->id}/transitions", ['action' => 'start'])->assertOk();
        $this->assertSame(0, TimelineEvent::count());
    }
}
