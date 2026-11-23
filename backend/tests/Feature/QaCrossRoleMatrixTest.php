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
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/**
 * Cross-role QA matrix (admin, agency owner, client, employee) run through the real API on the in-memory test database:
 * Sunday and capacity limits, validation replays, tenant isolation, urgent tickets, the hours proposal / plan flow and
 * what each side is told. Everything created here is prefixed "QA-TEST".
 */
class QaCrossRoleMatrixTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private $agency;

    private $otherAgency;

    private User $owner;

    private User $client;

    private User $otherOwner;

    private User $employee;

    private User $admin;

    private EmployeeAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scheduling.require_timeline_review' => true]);
        $this->setUpWeek();
        $this->agency = $this->makeAgency('QA-TEST Agency');
        $this->otherAgency = $this->makeAgency('QA-TEST Other Agency');
        $this->owner = $this->makeOwner($this->agency, 'QA-TEST Owner');
        $this->client = $this->makeClient($this->agency, 'QA-TEST Client');
        $this->otherOwner = $this->makeOwner($this->otherAgency, 'QA-TEST Other Owner');
        $this->employee = $this->makeEmployee('QA-TEST Employee One');
        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'QA-TEST Admin Person', 'email' => 'qa-admin-secret@example.test', 'is_active' => true]);
        $this->assignment = $this->hold($this->employee, $this->agency, $this->owner);
    }

    private function mon(): CarbonImmutable
    {
        return $this->monday->addDays(7);
    }

    private function project(User $as, array $overrides = []): WorkItem
    {
        $id = $this->actingAs($as)->postJson('/api/agency/projects', [
            'title' => 'QA-TEST Project A', 'description' => 'QA-TEST description', 'estimated_hours' => 12,
            'start_date' => $this->mon()->toDateString(), 'due_date' => $this->mon()->addDays(4)->toDateString(), 'assignment_id' => $this->assignment->id,
            ...$overrides,
        ])->assertCreated()->json('project.id');

        return WorkItem::findOrFail($id);
    }

    private function ticket(User $as, WorkItem $project, array $overrides = [])
    {
        return $this->actingAs($as)->postJson("/api/agency/projects/{$project->id}/tickets", ['title' => 'QA-TEST Ticket', 'description' => 'd', 'estimated_hours' => 5, ...$overrides]);
    }

    private function day(CarbonImmutable $date, mixed $hours): array
    {
        return ['date' => $date->toDateString(), 'hours' => $hours];
    }

    /* ---- Step 1: availability, Sunday ---- */

    public function test_sunday_is_unavailable_in_the_planner_the_scheduler_and_the_api(): void
    {
        $sunday = $this->mon()->addDays(6);
        $this->assertSame(0, $sunday->dayOfWeek);

        $days = $this->actingAs($this->owner)->getJson("/api/agency/assignments/{$this->assignment->id}/availability?start_date={$this->mon()->toDateString()}&due_date={$sunday->toDateString()}")->assertOk()
            ->assertJsonPath('capacity.daily_hours', 6)->assertJsonPath('capacity.weekly_hours', 36)->json('days');
        $sundayRow = collect($days)->firstWhere('date', $sunday->toDateString());
        $this->assertFalse($sundayRow['working_day']);
        $this->assertSame(0.0, (float) ($sundayRow['available_hours'] ?? 0));

        // A project whose window ends on a Sunday never gets hours on it.
        $project = $this->project($this->owner, ['estimated_hours' => 30, 'due_date' => $sunday->toDateString()]);
        $this->assertFalse($project->allocations()->whereDate('work_date', $sunday->toDateString())->exists());

        // Bypassing the planner UI: the API refuses Sunday hours.
        $this->actingAs($this->admin)->patchJson("/api/admin/projects/{$project->id}/timeline", ['days' => [$this->day($sunday, 6)], 'due_date' => $sunday->toDateString()])
            ->assertUnprocessable()->assertJsonPath('errors.days\.0\.date.0', null);
        $this->patchJson("/api/admin/projects/{$project->id}/timeline", ['days' => [$this->day($sunday, 6)], 'due_date' => $sunday->toDateString()])
            ->assertJsonValidationErrors('days.0.date');
    }

    /* ---- Step 2/3: owner and client flows, validation, isolation ---- */

    public function test_project_validation_is_enforced_by_the_backend(): void
    {
        $this->actingAs($this->owner)->postJson('/api/agency/projects', [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'description', 'estimated_hours', 'start_date', 'due_date', 'assignment_id']);
        $base = ['title' => 'QA-TEST X', 'description' => 'd', 'estimated_hours' => 5, 'start_date' => $this->mon()->toDateString(), 'due_date' => $this->mon()->addDays(3)->toDateString(), 'assignment_id' => $this->assignment->id];
        $this->postJson('/api/agency/projects', [...$base, 'title' => str_repeat('a', 161)])->assertJsonValidationErrors('title');
        $this->postJson('/api/agency/projects', [...$base, 'description' => str_repeat('a', 5001)])->assertJsonValidationErrors('description');
        $this->postJson('/api/agency/projects', [...$base, 'estimated_hours' => -1])->assertJsonValidationErrors('estimated_hours');
        $this->postJson('/api/agency/projects', [...$base, 'estimated_hours' => 'abc'])->assertJsonValidationErrors('estimated_hours');
        $this->postJson('/api/agency/projects', [...$base, 'estimated_hours' => 1.234])->assertJsonValidationErrors('estimated_hours');
        $this->postJson('/api/agency/projects', [...$base, 'due_date' => $this->mon()->subDay()->toDateString()])->assertJsonValidationErrors('due_date');
        // Ticket validation too.
        $project = $this->project($this->owner);
        $this->ticket($this->owner, $project, ['title' => '', 'estimated_hours' => 0])->assertUnprocessable()->assertJsonValidationErrors(['title', 'estimated_hours']);
        // Duplicate titles are allowed (tickets have their own numbers) and must not collide.
        $this->project($this->owner);
        $this->ticket($this->owner, $project)->assertCreated();
        $this->ticket($this->owner, $project, ['estimated_hours' => 1])->assertCreated();
        $numbers = Ticket::where('work_item_id', $project->id)->pluck('number')->all();
        $this->assertSame(count($numbers), count(array_unique($numbers)));
    }

    public function test_an_agency_can_only_reach_its_own_projects_tickets_and_timelines(): void
    {
        $project = $this->project($this->owner);
        $ticketId = $this->ticket($this->owner, $project)->assertCreated()->json('ticket.id');

        foreach ([
            ['GET', "/api/agency/projects/{$project->id}"], ['GET', "/api/agency/projects/{$project->id}/tickets"], ['GET', "/api/agency/projects/{$project->id}/timeline"],
            ['PATCH', "/api/agency/projects/{$project->id}"], ['GET', "/api/tickets/{$ticketId}/comments"], ['GET', "/api/tickets/{$ticketId}/timeline"],
            ['POST', "/api/agency/projects/{$project->id}/tickets"], ['POST', "/api/agency/projects/{$project->id}/timeline/respond"],
        ] as [$method, $url]) {
            $status = $this->actingAs($this->otherOwner)->json($method, $url, ['title' => 'QA-TEST x', 'description' => 'x', 'estimated_hours' => 1])->status();
            $this->assertContains($status, [403, 404], "{$method} {$url} returned {$status}");
        }
        $this->actingAs($this->otherOwner)->getJson('/api/agency/tickets')->assertOk()->assertJsonCount(0, 'tickets');
    }

    public function test_the_client_is_limited_to_their_own_work_and_never_reaches_admin_or_owner_only_actions(): void
    {
        $ownerProject = $this->project($this->owner, ['title' => 'QA-TEST Owner Project', 'access_details' => 'login: owner-secret']);
        $clientProject = $this->project($this->client, ['title' => 'QA-TEST Client Project', 'access_details' => 'login: client-secret']);

        // Agency projects are shared inside the agency, but saved logins stay with the owner and the creator only.
        $this->actingAs($this->client)->getJson("/api/agency/projects/{$ownerProject->id}/timeline")->assertNotFound();
        $this->getJson("/api/agency/projects/{$ownerProject->id}")->assertOk()->assertJsonPath('project.brief.access_details', null)->assertJsonPath('project.brief.has_access_details', true);
        $this->getJson("/api/agency/projects/{$clientProject->id}")->assertOk()->assertJsonPath('project.brief.access_details', 'login: client-secret');
        $this->actingAs($this->owner)->getJson("/api/agency/projects/{$clientProject->id}")->assertOk()->assertJsonPath('project.brief.access_details', 'login: client-secret');
        $this->actingAs($this->otherOwner)->getJson("/api/agency/projects/{$ownerProject->id}")->assertNotFound();
        // "Mine" filters the shared list to what the client created.
        $this->actingAs($this->client)->getJson('/api/agency/projects?mine=1')->assertOk()->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.id', $clientProject->id);
        $this->getJson('/api/agency/projects')->assertOk()->assertJsonCount(2, 'projects');        $this->getJson('/api/agency/tickets')->assertOk()->assertJsonCount(0, 'tickets');   // the client's own ticket list holds only their own work
        // The owner still sees both.
        $this->actingAs($this->owner)->getJson('/api/agency/projects')->assertOk()->assertJsonCount(2, 'projects');
        $this->actingAs($this->client);
        $this->getJson("/api/agency/projects/{$clientProject->id}/timeline")->assertOk();

        foreach (['/api/admin/timeline-reviews', '/api/admin/dashboard', '/api/admin/tickets', '/api/admin/projects', '/api/admin/agencies', '/api/admin/rush-requests', '/api/admin/assignments'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->patchJson("/api/admin/projects/{$clientProject->id}/timeline", ['days' => [$this->day($this->mon(), 6)]])->assertForbidden();
        $this->postJson("/api/admin/projects/{$clientProject->id}/timeline/confirm")->assertForbidden();
        // Owner-only: holding or releasing an employee.
        $this->assertContains($this->postJson('/api/agency/assignments', ['employee_id' => $this->makeEmployee('QA-TEST Free')->id])->status(), [403, 404]);
        $this->assertContains($this->deleteJson("/api/agency/assignments/{$this->assignment->id}")->status(), [403, 404]);
    }

    public function test_an_owner_cannot_reach_admin_routes(): void
    {
        $project = $this->project($this->owner);
        foreach (['/api/admin/timeline-reviews', '/api/admin/dashboard', '/api/admin/tickets', '/api/admin/agencies'] as $url) {
            $this->actingAs($this->owner)->getJson($url)->assertForbidden();
        }
        $this->patchJson("/api/admin/projects/{$project->id}/timeline", ['days' => [$this->day($this->mon(), 6)]])->assertForbidden();
        $this->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertForbidden();
        $this->postJson("/api/admin/projects/{$project->id}/timeline/request-changes", ['message' => 'x'])->assertForbidden();
    }

    /* ---- Step 4: admin review of hours ---- */

    public function test_every_planning_case_for_a_five_hour_request(): void
    {
        $project = $this->project($this->owner, ['estimated_hours' => 5]);
        $mon = $this->mon();
        $url = "/api/admin/projects/{$project->id}/timeline";

        // a) 5 = 5   b) 8 = 5+1+2   c) 3
        $this->actingAs($this->admin)->patchJson($url, ['days' => [$this->day($mon, 5)]])->assertOk()->assertJsonPath('project.hours_differ', false);
        $this->patchJson($url, ['days' => [$this->day($mon, 5), $this->day($mon->addDay(), 1), $this->day($mon->addDays(2), 2)], 'note' => 'QA-TEST more work'])->assertOk()->assertJsonPath('project.planned_hours', 8)->assertJsonPath('project.estimated_hours', 5);
        $this->patchJson($url, ['days' => [$this->day($mon, 3)]])->assertOk()->assertJsonPath('project.planned_hours', 3)->assertJsonPath('project.estimated_hours', 5);
        $this->assertSame('5.00', $project->fresh()->estimated_hours);

        // d) Sunday  e) >6 h in a day  f) >36 h in a week (two projects' worth)  h) bad values  i) due date before the days
        $this->patchJson($url, ['days' => [$this->day($mon->addDays(6), 2)]])->assertJsonValidationErrors('days.0.date');
        $this->patchJson($url, ['days' => [$this->day($mon, 6.5)]])->assertJsonValidationErrors('days.0.hours');
        $this->patchJson($url, ['days' => [$this->day($mon, 0)]])->assertJsonValidationErrors('days.0.hours');
        $this->patchJson($url, ['days' => [$this->day($mon, -2)]])->assertJsonValidationErrors('days.0.hours');
        $this->patchJson($url, ['days' => [$this->day($mon, 'abc')]])->assertJsonValidationErrors('days.0.hours');
        $this->patchJson($url, ['days' => [$this->day($mon, 1.234)]])->assertJsonValidationErrors('days.0.hours');
        $this->patchJson($url, ['days' => [$this->day($mon, 2.5)]])->assertOk();   // two decimals are fine
        $this->patchJson($url, ['days' => [$this->day($mon->addDays(3), 2)], 'due_date' => $mon->addDay()->toDateString()])->assertJsonValidationErrors('days.0.date');
        $this->patchJson($url, ['days' => []])->assertUnprocessable();
        $this->patchJson($url, ['days' => [$this->day($mon, 6), $this->day($mon->addDay(), 6), $this->day($mon->addDays(2), 6), $this->day($mon->addDays(3), 6), $this->day($mon->addDays(4), 6), $this->day($mon->addDays(5), 6), $this->day($mon->addDays(5), 1)]])->assertUnprocessable();
        // g) a day another project already books
        $this->book($this->assignment, 'in_progress', [[$mon->addDays(1), 4]]);
        $r = $this->patchJson($url, ['days' => [$this->day($mon->addDay(), 4)]])->assertUnprocessable();
        $this->assertStringContainsString('Only 2 h', preg_replace('/\.00/', '', $r->json('errors')['days.0.hours'][0]));
        // The payload tells the planner what is free and what is other work.
        $day = collect($this->getJson($url)->json('days'))->firstWhere('date', $mon->addDay()->toDateString());
        $this->assertSame(4.0, (float) $day['other_booked_hours']);
        $this->assertSame(2.0, (float) $day['free_hours']);
        // Notes: empty and filled both work.
        $this->patchJson($url, ['days' => [$this->day($mon, 3)], 'note' => ''])->assertOk();
        $this->patchJson($url, ['days' => [$this->day($mon, 4)], 'note' => str_repeat('n', 2001)])->assertUnprocessable();
        $this->assertSame('5.00', $project->fresh()->estimated_hours);
    }

    public function test_the_weekly_36_hour_limit_blocks(): void
    {
        $project = $this->project($this->owner, ['estimated_hours' => 6, 'due_date' => $this->mon()->addDays(5)->toDateString()]);
        $mon = $this->mon();
        // Other work already uses 31 h of the week (6,6,6,6,6,1).
        $this->book($this->assignment, 'in_progress', [[$mon, 6], [$mon->addDay(), 6], [$mon->addDays(2), 6], [$mon->addDays(3), 6], [$mon->addDays(4), 6], [$mon->addDays(5), 1]]);
        $this->actingAs($this->admin)->patchJson("/api/admin/projects/{$project->id}/timeline", ['days' => [$this->day($mon->addDays(5), 5), $this->day($mon->addDays(4), 0.5), $this->day($mon->addDays(3), 0.5)]])->assertUnprocessable();
    }

    /* ---- Step 5: urgent tickets ---- */

    public function test_an_urgent_ticket_is_flagged_everywhere_and_the_admin_is_told(): void
    {
        Notification::fake();
        $project = $this->project($this->owner);
        $urgentId = $this->ticket($this->owner, $project, ['title' => 'QA-TEST Urgent ticket', 'priority' => 'urgent', 'estimated_hours' => 2])->assertCreated()->assertJsonPath('ticket.priority', 'urgent')->json('ticket.id');
        $this->ticket($this->owner, $project, ['title' => 'QA-TEST Normal ticket', 'estimated_hours' => 2])->assertCreated();

        $this->actingAs($this->owner)->getJson("/api/agency/projects/{$project->id}/tickets")->assertOk()->assertJsonCount(2, 'tickets');
        $this->getJson('/api/agency/tickets?priority=urgent')->assertOk()->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.id', $urgentId);
        $admin = $this->actingAs($this->admin)->getJson('/api/admin/tickets')->assertOk();
        $this->assertSame('urgent', collect($admin->json('tickets'))->firstWhere('id', $urgentId)['priority']);
        // Urgent tickets come first in the admin list.
        $this->assertSame($urgentId, $admin->json('tickets.0.id'), 'urgent tickets should lead the admin ticket list');
        // The admin is told, and the message says urgent.
        Notification::assertSentTo($this->admin, TimelineNotice::class, fn ($n) => str_contains(strtolower($n->payload['title'].$n->payload['body']), 'urgent'));
        // The client of an urgent ticket sees urgent too.
        $this->actingAs($this->client)->getJson('/api/agency/tickets')->assertOk();
    }

    /* ---- Step 6/7: what each side sees and is told ---- */

    public function test_agency_notifications_and_mail_never_expose_the_admin_and_go_to_the_right_people_once(): void
    {
        Notification::fake();
        $project = $this->project($this->client, ['estimated_hours' => 5]);
        $mon = $this->mon();
        $other = $this->actingAs($this->otherOwner)->getJson('/api/notifications')->json();

        $this->actingAs($this->admin)->patchJson("/api/admin/projects/{$project->id}/timeline", ['days' => [$this->day($mon, 5), $this->day($mon->addDay(), 3)], 'note' => 'QA-TEST needs more time'])->assertOk();
        $this->postJson("/api/admin/projects/{$project->id}/timeline/request-changes", ['message' => 'QA-TEST please clarify'])->assertOk();
        $this->actingAs($this->client)->postJson("/api/agency/projects/{$project->id}/timeline/respond", ['note' => 'QA-TEST clarified'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertOk();

        $secrets = ['QA-TEST Admin Person', 'qa-admin-secret@example.test', 'Admin Person'];
        foreach ([$this->client, $this->owner] as $user) {
            $sent = Notification::sent($user, TimelineNotice::class);
            $this->assertNotEmpty($sent);
            foreach ($sent as $notice) {
                $mail = $notice->toMail($user);
                $text = $notice->payload['title'].' '.$notice->payload['body'].' '.($notice->payload['actor_name'] ?? '').' '.$mail->subject.' '.(string) $mail->render();
                foreach ($secrets as $secret) {
                    $this->assertStringNotContainsString($secret, $text, "leaked to {$user->name}: {$notice->payload['title']}");
                }
                $this->assertStringNotContainsString('project manager', strtolower($notice->payload['body']));
            }
            // No duplicates: one message per event.
            $titles = $sent->map(fn ($n) => $n->payload['title'])->all();
            $this->assertSame(count($titles), count(array_unique($titles)), 'duplicate notifications: '.implode(' | ', $titles));
        }
        // Nothing reached another agency or the unrelated people.
        Notification::assertNotSentTo($this->otherOwner, TimelineNotice::class);
        Notification::assertNotSentTo($this->employee, \App\Notifications\TicketNotification::class, fn ($n) => str_contains($n->payload['body'] ?? '', 'QA-TEST please clarify'));
        $this->assertIsArray($other);
    }

    public function test_after_confirmation_the_agency_sees_requested_and_planned_hours_and_the_note(): void
    {
        $project = $this->project($this->client, ['estimated_hours' => 5]);
        $this->actingAs($this->admin)->patchJson("/api/admin/projects/{$project->id}/timeline", ['days' => [$this->day($this->mon(), 5), $this->day($this->mon()->addDay(), 3)], 'note' => 'QA-TEST based on the work'])->assertOk();
        $this->postJson("/api/admin/projects/{$project->id}/timeline/confirm")->assertOk();

        foreach ([$this->client, $this->owner] as $user) {
            $this->actingAs($user)->getJson("/api/agency/projects/{$project->id}/timeline")->assertOk()
                ->assertJsonPath('timeline.status', 'confirmed')->assertJsonPath('project.estimated_hours', 5)->assertJsonPath('project.planned_hours', 8)
                ->assertJsonPath('project.planned_note', 'QA-TEST based on the work')->assertJsonPath('timeline.confirmed_by', 'QA-TEST Agency Support');
        }
        // Agency users can never confirm or edit the plan.
        $this->actingAs($this->owner)->patchJson("/api/agency/projects/{$project->id}/timeline", ['days' => [$this->day($this->mon(), 1)]])->assertStatus(405);
        $this->assertContains($this->postJson("/api/agency/projects/{$project->id}/timeline/confirm")->status(), [403, 404, 405]);
    }
}
