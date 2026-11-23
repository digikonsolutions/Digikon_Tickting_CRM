<?php

namespace Tests\Feature;

use App\Console\Commands\ExpireRushQuotes;
use App\Enums\Role;
use App\Jobs\NotifyQueueChange;
use App\Jobs\NotifyRushRequest;
use App\Models\AgencyCharge;
use App\Models\EmployeeAssignment;
use App\Models\RushRequest;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\QueueUpdated;
use App\Notifications\RushUpdated;
use App\Services\EmployeeQueuePlanner;
use App\Services\TicketNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

class QueueNotificationsTest extends TestCase
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
        config(['scheduling.require_timeline_review' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-12-07 08:00', config('app.timezone')));
        $this->setUpWeek();
        $this->mon = $this->today;
        $agency = $this->makeAgency('Acme');
        $agency->update(['name' => 'Acme Internal Name']);
        $this->owner = $this->makeOwner($agency, 'Olivia Owner');
        $this->client = $this->makeClient($agency, 'Carl Client');
        $this->employee = $this->makeEmployee('Emma');
        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Adam Admin']);
        $this->assignment = $this->hold($this->employee, $agency, $this->owner);
    }

    private function item(string $title, float $hours): WorkItem
    {
        $planner = app(EmployeeQueuePlanner::class);
        $item = WorkItem::create([
            'agency_id' => $this->assignment->agency_id, 'client_id' => $this->owner->id, 'assignment_id' => $this->assignment->id,
            'employee_id' => $this->employee->id, 'title' => $title, 'description' => 'D.', 'estimated_hours' => $hours,
            'start_date' => $this->mon->toDateString(), 'due_date' => $this->mon->addDays(20)->toDateString(), 'status' => 'scheduled',
            'queue_position' => $planner->nextPosition($this->employee),
        ]);
        $planner->replan($this->employee);

        return $item;
    }

    private function payload(): array
    {
        return [
            'type' => 'priority_insert', 'assignment_id' => $this->assignment->id, 'title' => 'Urgent fix',
            'description' => 'Checkout is broken.', 'estimated_hours' => 6, 'needed_by' => $this->mon->addDays(2)->toDateString(),
        ];
    }

    public function test_reordering_queues_a_job_and_tells_the_employee_in_app(): void
    {
        $a = $this->item('First', 6);
        $b = $this->item('Second', 6);

        Queue::fake();
        $this->actingAs($this->owner)->patchJson("/api/agency/assignments/{$this->assignment->id}/queue/reorder", ['ordered_work_item_ids' => [$b->id, $a->id]])->assertOk();
        Queue::assertPushed(NotifyQueueChange::class, fn (NotifyQueueChange $job): bool => $job->employeeId === $this->employee->id && $job->kind === 'reordered');

        Notification::fake();
        (new NotifyQueueChange($this->employee->id, 'reordered', $this->owner->id))->handle(app(TicketNotifier::class), app(EmployeeQueuePlanner::class));
        Notification::assertSentTo($this->employee, QueueUpdated::class, function (QueueUpdated $n): bool {
            return str_contains($n->payload['body'], 'Second') && ! str_contains(json_encode($n->payload), 'USD');
        });
        Notification::assertNotSentTo($this->owner, QueueUpdated::class);
    }

    public function test_the_rush_flow_dispatches_a_queued_job_for_each_step(): void
    {
        Queue::fake();
        $id = $this->actingAs($this->client)->postJson('/api/agency/rush-requests', $this->payload())->json('rush_request.id');
        Queue::assertPushed(NotifyRushRequest::class, fn (NotifyRushRequest $job): bool => $job->event === 'submitted' && $job->rushRequestId === $id);

        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$id/quote", ['amount_minor' => 5000])->assertOk();
        Queue::assertPushed(NotifyRushRequest::class, fn (NotifyRushRequest $job): bool => $job->event === 'quoted');

        $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$id/accept")->assertOk();
        Queue::assertPushed(NotifyRushRequest::class, fn (NotifyRushRequest $job): bool => $job->event === 'accepted');
        Queue::assertPushed(NotifyRushRequest::class, fn (NotifyRushRequest $job): bool => $job->event === 'fulfilled');
    }

    public function test_each_step_reaches_the_right_people_with_the_right_channels(): void
    {
        $second = User::factory()->create(['role' => Role::Admin]);
        $id = $this->actingAs($this->client)->postJson('/api/agency/rush-requests', $this->payload())->json('rush_request.id');
        $rush = RushRequest::query()->find($id);

        // Submitted: every admin, in-app and email.
        Notification::fake();
        $this->deliver('submitted', $id);
        foreach ([$this->admin, $second] as $admin) {
            Notification::assertSentTo($admin, RushUpdated::class, fn (RushUpdated $n, array $channels): bool => in_array('database', $channels, true) && in_array('mail', $channels, true));
        }
        Notification::assertNotSentTo($this->owner, RushUpdated::class);

        // Quoted: the owner only, email and in-app, branded for the agency.
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$id/quote", ['amount_minor' => 12345])->assertOk();
        Notification::fake();
        $this->deliver('quoted', $id);
        Notification::assertSentTo($this->owner, RushUpdated::class, function (RushUpdated $n, array $channels): bool {
            return in_array('database', $channels, true) && in_array('mail', $channels, true) && str_contains($n->payload['body'], '123.45 USD');
        });
        Notification::assertNotSentTo($this->client, RushUpdated::class);
        Notification::assertNotSentTo($this->admin, RushUpdated::class);

        // Accepted / declined / expired: admins.
        foreach (['accepted', 'declined', 'expired'] as $event) {
            Notification::fake();
            $this->deliver($event, $id);
            Notification::assertSentTo($this->admin, RushUpdated::class);
            Notification::assertNotSentTo($this->owner, RushUpdated::class);
        }
    }

    public function test_fulfilled_tells_the_employee_without_money_and_the_requester(): void
    {
        $id = $this->actingAs($this->client)->postJson('/api/agency/rush-requests', $this->payload())->json('rush_request.id');
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$id/quote", ['amount_minor' => 99900])->assertOk();
        Notification::fake();
        $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$id/accept")->assertOk();
        $this->deliver('fulfilled', $id, $this->owner->id);

        Notification::assertSentTo($this->employee, RushUpdated::class, function (RushUpdated $n): bool {
            $text = json_encode($n->toArray($this->employee));

            return ! str_contains($text, '999') && ! str_contains($text, 'USD') && str_contains($text, 'Urgent fix');
        });
        Notification::assertSentTo($this->client, RushUpdated::class);
        // The owner accepted it and already knows.
        Notification::assertNotSentTo($this->owner, RushUpdated::class);
    }

    public function test_agency_notifications_use_the_agency_brand_and_no_platform_words(): void
    {
        $id = $this->actingAs($this->client)->postJson('/api/agency/rush-requests', [
            'type' => 'extra_employee', 'title' => 'Help', 'description' => 'Need hands.', 'estimated_hours' => 6, 'needed_by' => $this->mon->addDays(3)->toDateString(),
        ])->json('rush_request.id');
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$id/reject", ['reason' => 'Pool is empty, said Adam Admin.'])->assertOk();

        Notification::fake();
        $this->deliver('rejected', $id);
        Notification::assertSentTo($this->owner, RushUpdated::class);
        Notification::assertSentTo($this->client, RushUpdated::class);
        $sent = Notification::sent($this->owner, RushUpdated::class)->first();
        $json = json_encode($sent->toArray($this->owner));
        $this->assertStringContainsString('No team member is available for this request.', $json);
        foreach (['ClientHub', 'Adam Admin', 'Pool', 'shared', 'Acme Internal Name'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $mail = $sent->toMail($this->owner);
        $this->assertStringNotContainsString('ClientHub', implode(' ', array_merge($mail->introLines, [$mail->subject, $mail->actionText ?? ''])));
    }

    public function test_the_expire_quotes_command_expires_and_notifies(): void
    {
        $id = $this->actingAs($this->client)->postJson('/api/agency/rush-requests', $this->payload())->json('rush_request.id');
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$id/quote", ['amount_minor' => 5000, 'valid_hours' => 1])->assertOk();

        $this->artisan('rush:expire-quotes')->expectsOutputToContain('Expired 0')->assertSuccessful();
        $this->travel(61)->minutes();
        Queue::fake();
        $this->artisan('rush:expire-quotes')->expectsOutputToContain('Expired 1')->assertSuccessful();
        $this->assertSame('expired', RushRequest::query()->find($id)->status);
        Queue::assertPushed(NotifyRushRequest::class, fn (NotifyRushRequest $job): bool => $job->event === 'expired');
        $this->artisan('rush:expire-quotes')->expectsOutputToContain('Expired 0');
        $this->assertSame(0, AgencyCharge::query()->count());
    }

    public function test_the_commands_are_scheduled_every_five_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command, 'rush:expire-quotes') || str_contains($event->command, 'assignments:expire-temporary'));

        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame('*/5 * * * *', $event->expression);
        }
        $this->assertTrue(class_exists(ExpireRushQuotes::class));
    }

    private function deliver(string $event, int $id, ?int $actor = null): void
    {
        (new NotifyRushRequest($id, $event, $actor))->handle(app(TicketNotifier::class));
    }
}
