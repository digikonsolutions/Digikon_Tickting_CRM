<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

class TimeTrackingTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
        $this->ticket = $this->makeTicket('other', 'Landing page', 4);
        $this->transition('employee', $this->ticket, 'start');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function today(int $back = 0): string
    {
        return CarbonImmutable::now(config('app.timezone'))->subDays($back)->toDateString();
    }

    private function log(string $by, Ticket $ticket, array $data)
    {
        return $this->actingAs($this->u[$by])->postJson("/api/tickets/{$ticket->id}/time", $data);
    }

    public function test_only_the_assigned_employee_can_log_and_start_timers(): void
    {
        foreach (['admin', 'owner', 'client'] as $who) {
            $this->log($who, $this->ticket, ['entry_date' => $this->today(), 'minutes' => 30])->assertForbidden();
            $this->actingAs($this->u[$who])->postJson("/api/tickets/{$this->ticket->id}/time/start")->assertForbidden();
        }
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 30])->assertCreated();
    }

    public function test_outsiders_cannot_see_ticket_time(): void
    {
        $this->actingAs($this->outsider())->getJson("/api/tickets/{$this->ticket->id}/time")->assertNotFound();
    }

    public function test_manual_entry_limits(): void
    {
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 0])->assertUnprocessable();
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 721])->assertUnprocessable();
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(-1), 'minutes' => 30])->assertUnprocessable()->assertJsonValidationErrors('entry_date');
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(15), 'minutes' => 30])->assertUnprocessable()->assertJsonValidationErrors('entry_date');
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(14), 'minutes' => 30])->assertCreated();
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 720])->assertCreated();
    }

    public function test_daily_total_is_capped_at_sixteen_hours(): void
    {
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 720])->assertCreated();
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 240])->assertCreated();
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 1])->assertUnprocessable()->assertJsonValidationErrors('minutes');
    }

    public function test_each_manual_entry_records_one_public_event(): void
    {
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 45])->assertCreated();

        $events = $this->ticket->events()->where('type', 'time_logged')->get();
        $this->assertCount(1, $events);
        $this->assertSame(45, $events[0]->payload['minutes']);
        $this->assertFalse((bool) ($events[0]->is_internal ?? false));
    }

    public function test_one_running_timer_per_employee_and_switching_stops_the_first(): void
    {
        $second = $this->makeTicket('other', 'Second', 2);
        $this->transition('employee', $second, 'start');

        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$this->ticket->id}/time/start")->assertCreated()->assertJsonPath('stopped', null);
        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$this->ticket->id}/time/start")->assertUnprocessable();
        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$second->id}/time/start")->assertCreated()->assertJsonPath('stopped.stop_reason', 'switched');

        $this->assertSame(1, TimeEntry::whereNull('ended_at')->where('source', 'timer')->count());
        $this->actingAs($this->u['employee'])->getJson('/api/time/active')->assertOk()->assertJsonPath('entry.ticket.id', $second->id);
    }

    public function test_starting_a_timer_records_no_event_but_stopping_does(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$this->ticket->id}/time/start")->assertCreated();
        $this->assertSame(0, $this->ticket->events()->where('type', 'time_logged')->count());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond()->addMinutes(90));
        $this->actingAs($this->u['employee'])->postJson('/api/time/stop')->assertOk()->assertJsonPath('entry.minutes', 90);
        $this->assertSame(1, $this->ticket->events()->where('type', 'time_logged')->count());
        $this->actingAs($this->u['employee'])->postJson('/api/time/stop')->assertUnprocessable();
    }

    public function test_timer_only_runs_while_in_progress_or_changes_requested(): void
    {
        $fresh = $this->makeTicket('other', 'Not started', 1);
        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$fresh->id}/time/start")->assertUnprocessable();
    }

    public function test_status_changes_stop_the_running_timer(): void
    {
        foreach (['send_to_review' => 'in_review', 'request_client_input' => 'waiting_on_client', 'mark_blocked' => 'blocked'] as $action => $status) {
            $ticket = $this->makeTicket('other', 'T '.$action, 2);
            $this->transition('employee', $ticket, 'start');
            $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$ticket->id}/time/start")->assertCreated();

            $comment = in_array($action, ['request_client_input', 'mark_blocked'], true) ? 'Reason' : null;
            $this->transition('employee', $ticket, $action, $comment);

            $this->assertSame($status, $ticket->fresh()->status);
            $this->assertNull(TimeEntry::where('ticket_id', $ticket->id)->whereNull('ended_at')->first());
            $this->assertSame('status_'.$status, TimeEntry::where('ticket_id', $ticket->id)->first()->stop_reason);
        }
    }

    public function test_cancelling_the_ticket_stops_the_timer(): void
    {
        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$this->ticket->id}/time/start")->assertCreated();
        $this->actingAs($this->u['client'])->deleteJson("/api/agency/tickets/{$this->ticket->id}")->assertOk();

        $this->assertSame(0, TimeEntry::whereNull('ended_at')->count());
        $this->assertSame('ticket_cancelled', TimeEntry::first()->stop_reason);
    }

    public function test_stale_timers_are_closed_at_the_cap_and_flagged(): void
    {
        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$this->ticket->id}/time/start")->assertCreated();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(20));

        $this->artisan('time:stop-stale-timers')->assertSuccessful();

        $entry = TimeEntry::first();
        $this->assertSame(720, $entry->minutes);
        $this->assertTrue($entry->auto_stopped);
        $this->assertSame('stale', $entry->stop_reason);
    }

    public function test_employee_can_edit_own_entry_for_48_hours_only(): void
    {
        $id = $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 30])->json('entry.id');

        $this->actingAs($this->u['employee'])->patchJson("/api/time/{$id}", ['minutes' => 60])->assertOk()->assertJsonPath('entry.minutes', 60);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(49));
        $this->actingAs($this->u['employee'])->patchJson("/api/time/{$id}", ['minutes' => 90])->assertForbidden();
        $this->actingAs($this->u['employee'])->deleteJson("/api/time/{$id}")->assertForbidden();
    }

    public function test_admin_changes_need_a_reason_and_agency_users_cannot_change_anything(): void
    {
        $id = $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 30])->json('entry.id');

        $this->actingAs($this->u['admin'])->patchJson("/api/time/{$id}", ['minutes' => 20])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->u['admin'])->patchJson("/api/time/{$id}", ['minutes' => 20, 'reason' => 'Client dispute'])->assertOk();
        $this->actingAs($this->u['admin'])->deleteJson("/api/time/{$id}")->assertUnprocessable();
        $this->actingAs($this->u['admin'])->deleteJson("/api/time/{$id}", ['reason' => 'Logged on wrong ticket'])->assertOk();
        $this->assertSoftDeleted('time_entries', ['id' => $id]);
        $this->assertSame('Logged on wrong ticket', TimeEntry::withTrashed()->find($id)->modified_reason);

        $other = $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 30])->json('entry.id');
        $this->actingAs($this->u['owner'])->patchJson("/api/time/{$other}", ['minutes' => 5])->assertForbidden();
        $this->actingAs($this->u['client'])->deleteJson("/api/time/{$other}")->assertForbidden();
    }

    public function test_another_employee_cannot_touch_the_entry(): void
    {
        $id = $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 30])->json('entry.id');
        $stranger = \App\Models\User::factory()->create(['role' => \App\Enums\Role::Employee]);

        $this->actingAs($stranger)->patchJson("/api/time/{$id}", ['minutes' => 5])->assertNotFound();
    }

    public function test_agency_visibility_flag_hides_entries_but_not_totals(): void
    {
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 90])->assertCreated();

        $this->actingAs($this->u['client'])->getJson("/api/tickets/{$this->ticket->id}/time")
            ->assertOk()->assertJsonCount(1, 'entries')->assertJsonPath('totals.logged_hours', 1.5);

        config(['time.agency_sees_entries' => false]);
        $this->actingAs($this->u['client'])->getJson("/api/tickets/{$this->ticket->id}/time")
            ->assertOk()->assertJsonCount(0, 'entries')->assertJsonPath('entries_visible', false)->assertJsonPath('totals.logged_hours', 1.5);
        $this->actingAs($this->u['employee'])->getJson("/api/tickets/{$this->ticket->id}/time")->assertJsonCount(1, 'entries');
    }

    public function test_ticket_payload_carries_logged_hours_and_over_estimate(): void
    {
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 120])->assertCreated();
        $this->actingAs($this->u['client'])->getJson("/api/tickets/key/{$this->ticket->key}")
            ->assertJsonPath('ticket.logged_hours', 2)->assertJsonPath('ticket.over_estimate', false)->assertJsonPath('ticket.timer_running', false);

        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 180])->assertCreated();
        $this->actingAs($this->u['client'])->getJson("/api/tickets/key/{$this->ticket->key}")
            ->assertJsonPath('ticket.logged_hours', 5)->assertJsonPath('ticket.over_estimate', true);

        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$this->ticket->id}/time/start")->assertCreated();
        $this->actingAs($this->u['employee'])->getJson("/api/tickets/key/{$this->ticket->key}")->assertJsonPath('ticket.timer_running', true);
        $this->actingAs($this->u['client'])->getJson("/api/tickets/key/{$this->ticket->key}")->assertJsonPath('ticket.timer_running', false);
    }

    public function test_weekly_timesheet_matches_entries(): void
    {
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek();
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $this->log('employee', $this->ticket, ['entry_date' => $today, 'minutes' => 90])->assertCreated();
        $this->log('employee', $this->ticket, ['entry_date' => $today, 'minutes' => 30])->assertCreated();

        $week = $this->actingAs($this->u['employee'])->getJson('/api/time/week?week='.$today)->assertOk();
        $week->assertJsonPath('week_start', $monday->toDateString())->assertJsonCount(6, 'days')->assertJsonPath('totals.logged_hours', 2);
        $day = collect($week->json('days'))->firstWhere('date', $today);
        if ($day) {
            $this->assertSame(2.0, (float) $day['logged_hours']);
        }
        $this->assertSame(2.0, (float) $week->json('tickets.0.logged_hours'));
        $this->actingAs($this->u['client'])->getJson('/api/time/week')->assertForbidden();
    }

    public function test_summaries_match_fixtures_and_respect_scope(): void
    {
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 150])->assertCreated();
        $range = '?from='.$this->today(5).'&to='.$this->today();

        $admin = $this->actingAs($this->u['admin'])->getJson('/api/admin/time-summary'.$range.'&group_by=employee')->assertOk();
        $admin->assertJsonPath('rows.0.group.id', $this->u['employee']->id)->assertJsonPath('rows.0.logged_hours', 2.5)->assertJsonPath('totals.logged_hours', 2.5);
        $this->actingAs($this->u['admin'])->getJson('/api/admin/time-summary'.$range.'&group_by=agency')->assertJsonPath('rows.0.group.id', $this->agency->id);

        $this->actingAs($this->u['owner'])->getJson('/api/agency/time-summary'.$range.'&group_by=project')
            ->assertOk()->assertJsonPath('rows.0.logged_hours', 2.5)->assertJsonPath('rows.0.estimated_hours', 4);
        $this->actingAs($this->u['owner'])->getJson('/api/agency/time-summary'.$range.'&group_by=agency')->assertUnprocessable();
        $this->actingAs($this->u['owner'])->getJson('/api/admin/time-summary'.$range.'&group_by=employee')->assertForbidden();
        $this->actingAs($this->u['client'])->getJson('/api/agency/time-summary'.$range.'&group_by=project')->assertForbidden();

        $this->actingAs($this->outsider())->getJson('/api/agency/time-summary'.$range.'&group_by=project')->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_summary_csv_download(): void
    {
        $this->log('employee', $this->ticket, ['entry_date' => $this->today(), 'minutes' => 60])->assertCreated();
        $response = $this->actingAs($this->u['admin'])->get('/api/admin/time-summary?from='.$this->today(2).'&to='.$this->today().'&group_by=employee&format=csv');

        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Employee,"Logged hours"', $csv);
        $this->assertStringContainsString('Total,1', $csv);
    }

    public function test_ticket_lists_do_not_run_a_query_per_ticket(): void
    {
        $count = function (): int {
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->actingAs($this->u['employee'])->getJson('/api/work-items/mine/tickets')->assertOk();

            return count(\DB::getQueryLog());
        };
        $before = $count();
        foreach (range(1, 4) as $n) {
            $this->makeTicket('other', 'Extra '.$n, 1);
        }
        $this->assertSame($before, $count());
    }
}
