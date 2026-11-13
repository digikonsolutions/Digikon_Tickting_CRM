<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\TicketAssigned;
use App\Notifications\TicketCancelled;
use App\Notifications\TicketCommented;
use App\Notifications\TicketNeedsYourInput;
use App\Notifications\TicketRescheduled;
use App\Notifications\TicketStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $u = [];

    private WorkItem $project;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = Agency::create(['name' => 'Northwind', 'ticket_prefix' => 'NORT']);
        $this->u['owner'] = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $this->u['client'] = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $this->u['other'] = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $this->u['employee'] = User::factory()->create(['role' => Role::Employee]);
        $this->u['admin'] = User::factory()->create(['role' => Role::Admin]);
        $assignment = EmployeeAssignment::create([
            'holder_id' => $this->u['client']->id, 'agency_id' => $agency->id, 'employee_id' => $this->u['employee']->id,
            'status' => 'active', 'active_employee_id' => $this->u['employee']->id, 'started_at' => now(),
        ]);
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(2);
        $this->project = WorkItem::create([
            'agency_id' => $agency->id, 'client_id' => $this->u['client']->id, 'assignment_id' => $assignment->id,
            'employee_id' => $this->u['employee']->id, 'title' => 'Project', 'description' => 'Description.',
            'estimated_hours' => 30, 'start_date' => $monday->toDateString(), 'due_date' => $monday->addDays(5)->toDateString(),
            'status' => 'scheduled',
        ]);
        foreach (range(0, 4) as $offset) {
            $this->project->allocations()->create(['employee_id' => $this->u['employee']->id, 'work_date' => $monday->addDays($offset)->toDateString(), 'hours' => 6]);
        }
    }

    private function makeTicket(string $by = 'other', string $title = 'Landing page', float $hours = 2): Ticket
    {
        $id = $this->actingAs($this->u[$by])->postJson('/api/agency/projects/'.$this->project->id.'/tickets', [
            'title' => $title, 'description' => 'Details.', 'estimated_hours' => $hours,
        ])->assertCreated()->json('ticket.id');

        return Ticket::findOrFail($id);
    }

    private function transition(string $by, Ticket $ticket, string $action, ?string $comment = null): void
    {
        $this->actingAs($this->u[$by])->postJson('/api/tickets/'.$ticket->id.'/transitions', array_filter(['action' => $action, 'comment' => $comment]))->assertOk();
    }

    /** @param list<string> $names */
    private function assertOnly(string $class, array $names, ?callable $check = null): void
    {
        foreach ($this->u as $name => $user) {
            if (in_array($name, $names, true)) {
                Notification::assertSentTo($user, $class, $check);
            } else {
                Notification::assertNotSentTo($user, $class);
            }
        }
    }

    public function test_created_notifies_the_assigned_employee_only(): void
    {
        Notification::fake();
        $this->makeTicket('client');

        $this->assertOnly(TicketAssigned::class, ['employee']);
    }

    public function test_public_comment_matrix_goes_to_the_other_side_and_never_to_the_actor(): void
    {
        $ticket = $this->makeTicket('other');
        Notification::fake();

        $this->actingAs($this->u['client'])->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'From the client'])->assertCreated();
        $this->assertOnly(TicketCommented::class, ['employee'], fn ($n) => $n->payload['excerpt'] === 'From the client');

        Notification::fake();
        $this->actingAs($this->u['employee'])->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'From the specialist'])->assertCreated();
        $this->assertOnly(TicketCommented::class, ['owner', 'other']);
    }

    public function test_internal_comments_only_reach_admins_and_the_assigned_employee_and_carry_no_text(): void
    {
        $ticket = $this->makeTicket('other');
        Notification::fake();

        $this->actingAs($this->u['employee'])->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'Secret client moan', 'is_internal' => true])->assertCreated();
        $this->assertOnly(TicketCommented::class, ['admin'], function ($n) {
            return ($n->payload['excerpt'] ?? null) === null && ! str_contains(json_encode($n->payload), 'Secret');
        });

        Notification::fake();
        $this->actingAs($this->u['admin'])->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'Admin note', 'is_internal' => true])->assertCreated();
        $this->assertOnly(TicketCommented::class, ['employee']);
    }

    public function test_status_matrix(): void
    {
        $ticket = $this->makeTicket('other');
        $this->transition('employee', $ticket, 'start');

        Notification::fake();
        $this->transition('employee', $ticket, 'send_to_review');
        $this->assertOnly(TicketNeedsYourInput::class, ['owner', 'other'], fn ($n) => $n->payload['title'] === 'Needs your review');

        Notification::fake();
        $this->transition('client', $ticket, 'request_changes', 'Please change the colours');
        $this->assertOnly(TicketStatusChanged::class, ['employee'], fn ($n) => $n->payload['excerpt'] === 'Please change the colours');

        $this->transition('employee', $ticket, 'restart_work');
        Notification::fake();
        $this->transition('employee', $ticket, 'request_client_input', 'Which font do you prefer?');
        $this->assertOnly(TicketNeedsYourInput::class, ['owner', 'other'], fn ($n) => $n->payload['excerpt'] === 'Which font do you prefer?');

        $this->transition('client', $ticket, 'provide_input', 'Use Inter');
        Notification::fake();
        $this->transition('employee', $ticket, 'mark_blocked', 'Waiting for the logo');
        $this->assertOnly(TicketStatusChanged::class, ['owner', 'admin']);

        $this->transition('employee', $ticket, 'unblock');
        $this->transition('employee', $ticket, 'send_to_review');
        Notification::fake();
        $this->transition('owner', $ticket, 'approve');
        $this->assertOnly(TicketStatusChanged::class, ['employee', 'other'], fn ($n) => $n->payload['title'] === 'Ticket approved');
    }

    public function test_cancelled_goes_to_creator_and_employee_but_not_the_actor(): void
    {
        $ticket = $this->makeTicket('other');
        Notification::fake();

        $this->actingAs($this->u['client'])->deleteJson('/api/agency/tickets/'.$ticket->id)->assertOk();
        $this->assertOnly(TicketCancelled::class, ['other', 'employee']);

        $second = $this->makeTicket('other', 'Second');
        Notification::fake();
        $this->actingAs($this->u['other'])->deleteJson('/api/agency/tickets/'.$second->id)->assertOk();
        $this->assertOnly(TicketCancelled::class, ['employee']);
    }

    public function test_rescheduled_sends_one_notification_per_moved_ticket_and_not_to_the_actor(): void
    {
        $first = $this->makeTicket('other', 'First', 6);
        $second = $this->makeTicket('other', 'Second', 6);
        $third = $this->makeTicket('other', 'Third', 6);
        Notification::fake();

        // Moving the last ticket to the front pushes the other two back by one slot each.
        $this->actingAs($this->u['client'])->patchJson('/api/agency/projects/'.$this->project->id.'/tickets/reorder', [
            'ticket_ids' => [$third->id, $first->id, $second->id],
        ])->assertOk();

        $moved = Notification::sent($this->u['other'], TicketRescheduled::class);
        $this->assertGreaterThanOrEqual(1, $moved->count());
        $this->assertSame($moved->count(), $moved->pluck('payload.ticket_id')->unique()->count(), 'one notification per ticket');
        $this->assertSame($moved->count(), Notification::sent($this->u['owner'], TicketRescheduled::class)->count());
        Notification::assertNotSentTo($this->u['client'], TicketRescheduled::class);
        Notification::assertNotSentTo($this->u['employee'], TicketRescheduled::class);
    }

    public function test_inactive_users_and_users_of_suspended_agencies_never_receive_anything(): void
    {
        $ticket = $this->makeTicket('other');
        $this->u['owner']->update(['is_active' => false]);
        Notification::fake();

        $this->actingAs($this->u['employee'])->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'Hello'])->assertCreated();

        Notification::assertNotSentTo($this->u['owner'], TicketCommented::class);
        Notification::assertSentTo($this->u['other'], TicketCommented::class);

        // The notification class itself also refuses to use any channel for an inactive user.
        $this->assertSame([], (new TicketCommented(['type' => 'comment', 'ticket_id' => 1, 'ticket_key' => 'X-1', 'title' => 't', 'body' => 'b', 'url_path' => '/tickets/X-1', 'actor_name' => 'a']))->via($this->u['owner']->fresh()));
    }

    public function test_agency_users_never_get_or_read_internal_comment_notifications_in_the_database_or_api(): void
    {
        $ticket = $this->makeTicket('other');
        $this->actingAs($this->u['employee'])->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'Internal secret', 'is_internal' => true])->assertCreated();

        foreach (['owner', 'client', 'other'] as $name) {
            $this->assertSame(0, DatabaseNotification::where('notifiable_id', $this->u[$name]->id)->where('data->type', 'comment')->count());
            $this->actingAs($this->u[$name])->getJson('/api/notifications')->assertOk()->assertJsonMissing(['body' => 'Internal secret']);
        }
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $this->u['admin']->id)->where('data->type', 'comment')->count());
        $this->assertStringNotContainsString('Internal secret', DatabaseNotification::where('notifiable_id', $this->u['admin']->id)->first()->data['body']);
    }

    public function test_notifications_for_the_same_ticket_and_type_are_coalesced_for_ten_minutes(): void
    {
        $ticket = $this->makeTicket('other');
        $comment = fn (string $body) => $this->actingAs($this->u['client'])->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => $body])->assertCreated();

        $comment('one');
        $comment('two');
        $comment('three');
        $rows = DatabaseNotification::where('notifiable_id', $this->u['employee']->id)->where('data->type', 'comment')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows->first()->data['count']);
        $this->assertNull($rows->first()->read_at);

        // Once it is read, or after ten minutes, a fresh notification is created.
        $rows->first()->markAsRead();
        $comment('four');
        $this->assertSame(2, DatabaseNotification::where('notifiable_id', $this->u['employee']->id)->where('data->type', 'comment')->count());

        $this->travel(11)->minutes();
        $comment('five');
        $this->assertSame(3, DatabaseNotification::where('notifiable_id', $this->u['employee']->id)->where('data->type', 'comment')->count());
    }

    public function test_preferences_control_channels_and_defaults_apply_when_no_row_exists(): void
    {
        $ticket = $this->makeTicket('other');
        $this->transition('employee', $ticket, 'start');

        // Defaults: needs_input emails, comments do not.
        Notification::fake();
        $this->transition('employee', $ticket, 'send_to_review');
        Notification::assertSentTo($this->u['other'], TicketNeedsYourInput::class, fn ($n, $channels) => $channels === ['database', 'mail']);
        Notification::fake();
        $this->actingAs($this->u['employee'])->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'hi'])->assertCreated();
        Notification::assertSentTo($this->u['other'], TicketCommented::class, fn ($n, $channels) => $channels === ['database']);

        // Overrides: email comments on, needs_input email off, comments in-app off.
        $this->actingAs($this->u['other'])->putJson('/api/notifications/preferences', ['preferences' => [
            ['type' => 'comment', 'in_app' => false, 'email' => true],
            ['type' => 'needs_input', 'email' => false],
        ]])->assertOk();
        Notification::fake();
        $this->actingAs($this->u['employee'])->postJson('/api/tickets/'.$ticket->id.'/comments', ['body' => 'again'])->assertCreated();
        Notification::assertSentTo($this->u['other'], TicketCommented::class, fn ($n, $channels) => $channels === ['mail']);
        $this->assertSame(['database'], (new TicketNeedsYourInput(['type' => 'needs_input', 'ticket_id' => 1, 'ticket_key' => 'X-1', 'title' => 't', 'body' => 'b', 'url_path' => '/tickets/X-1', 'actor_name' => 'a']))->via($this->u['other']->fresh()));
    }

    public function test_needs_input_in_app_is_locked_and_the_preferences_payload_says_so(): void
    {
        $response = $this->actingAs($this->u['client'])->putJson('/api/notifications/preferences', ['preferences' => [
            ['type' => 'needs_input', 'in_app' => false, 'email' => false],
        ]])->assertOk();

        $row = collect($response->json('preferences'))->firstWhere('type', 'needs_input');
        $this->assertTrue($row['locked']);
        $this->assertTrue($row['in_app']);
        $this->assertFalse($row['email']);
        $this->assertFalse(collect($this->getJson('/api/notifications/preferences')->json('preferences'))->firstWhere('type', 'comment')['locked']);
        $this->putJson('/api/notifications/preferences', ['preferences' => [['type' => 'bogus', 'in_app' => true]]])->assertUnprocessable();
    }

    public function test_mail_has_the_subject_excerpt_button_and_settings_footer(): void
    {
        config(['app.frontend_url' => 'https://app.example.com']);
        $ticket = $this->makeTicket('other');
        $notification = new TicketCommented([
            'type' => 'comment', 'ticket_id' => $ticket->id, 'ticket_key' => $ticket->key, 'title' => 'New comment',
            'body' => 'Sam commented on '.$ticket->key.'.', 'excerpt' => 'Short public excerpt', 'url_path' => '/tickets/'.$ticket->key, 'actor_name' => 'Sam',
        ]);

        $mail = $notification->toMail($this->u['other']);
        $this->assertSame("[{$ticket->key}] New comment", $mail->subject);
        $rendered = (string) $mail->render();
        $this->assertStringContainsString('Short public excerpt', $rendered);
        $this->assertStringContainsString('https://app.example.com/tickets/'.$ticket->key, $rendered);
        $this->assertStringContainsString('https://app.example.com/settings/notifications', $rendered);
    }

    public function test_notification_api_lists_counts_reads_and_scopes_to_the_owner(): void
    {
        $ticket = $this->makeTicket('other');
        foreach (range(1, 3) as $number) {
            $other = $this->makeTicket('other', 'More '.$number);
        }
        $employee = $this->u['employee'];
        $this->actingAs($employee)->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('count', 4);
        $list = $this->getJson('/api/notifications')->assertOk()->assertJsonCount(4, 'notifications')->assertJsonPath('unread_count', 4);
        $first = $list->json('notifications.0');
        $this->assertSame('assigned', $first['type']);
        $this->assertStringStartsWith('/tickets/NORT-', $first['data']['url_path']);

        // Someone else cannot touch it.
        $this->actingAs($this->u['admin'])->postJson('/api/notifications/'.$first['id'].'/read')->assertNotFound();
        $this->assertNull(DatabaseNotification::find($first['id'])->read_at);

        $this->actingAs($employee)->postJson('/api/notifications/'.$first['id'].'/read')->assertOk()->assertJsonPath('unread_count', 3);
        $this->getJson('/api/notifications?unread=1')->assertJsonCount(3, 'notifications');
        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('count', 0);
    }

    public function test_notification_list_is_cursor_paginated_by_twenty(): void
    {
        foreach (range(1, 22) as $number) {
            DatabaseNotification::create([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'type' => TicketAssigned::class,
                'notifiable_type' => User::class, 'notifiable_id' => $this->u['client']->id,
                'data' => ['type' => 'assigned', 'ticket_id' => $number, 'ticket_key' => 'NORT-'.$number, 'title' => 't', 'body' => 'b', 'url_path' => '/tickets/NORT-'.$number, 'actor_name' => 'a', 'count' => 1],
                'created_at' => now()->subMinutes(100 - $number),
            ]);
        }

        $page = $this->actingAs($this->u['client'])->getJson('/api/notifications')->assertOk()->assertJsonCount(20, 'notifications');
        $this->assertNotNull($page->json('next_cursor'));
        $second = $this->getJson('/api/notifications?cursor='.$page->json('next_cursor'))->assertOk()->assertJsonCount(2, 'notifications');
        $this->assertNull($second->json('next_cursor'));
    }

    public function test_notification_endpoints_require_authentication_and_are_rate_limited(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
        $this->postJson('/api/notifications/read-all')->assertUnauthorized();
        $this->putJson('/api/notifications/preferences', [])->assertUnauthorized();

        $this->actingAs($this->u['client']);
        foreach (range(1, 60) as $i) {
            $this->getJson('/api/notifications/unread-count')->assertOk();
        }
        $this->getJson('/api/notifications/unread-count')->assertStatus(429);
    }
}
