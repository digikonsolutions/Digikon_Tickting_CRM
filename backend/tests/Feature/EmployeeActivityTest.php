<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmployeeActivityLog;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

class EmployeeActivityTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
        $this->u['lead'] = User::factory()->create(['role' => Role::TeamLead]);
        $this->u['employee']->forceFill(['password' => 'Passw0rd!Passw0rd'])->save();
        $this->ticket = $this->makeTicket('other', 'Landing page', 4);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function types(): array
    {
        return EmployeeActivityLog::orderBy('id')->pluck('event_type')->all();
    }

    public function test_employee_login_and_logout_start_and_end_a_session(): void
    {
        $this->postJson('/api/auth/login', ['email' => $this->u['employee']->email, 'password' => 'Passw0rd!Passw0rd'])->assertOk();

        $this->assertSame(['login', 'session_start'], $this->types());
        $this->assertNotNull(EmployeeActivityLog::first()->ip_address);
        $this->assertSame(1, WorkSession::whereNull('ended_at')->count());

        $this->postJson('/api/auth/logout')->assertOk();

        $this->assertSame(['login', 'session_start', 'session_end', 'logout'], $this->types());
        $this->assertSame('logout', WorkSession::first()->ended_reason);
    }

    public function test_other_roles_are_never_recorded(): void
    {
        foreach (['owner', 'client', 'admin'] as $who) {
            $this->actingAs($this->u[$who])->postJson('/api/auth/logout')->assertOk();
        }
        $this->assertSame(0, EmployeeActivityLog::count());
    }

    public function test_status_comment_and_project_events_are_logged_with_server_values(): void
    {
        $this->transition('employee', $this->ticket, 'start');
        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$this->ticket->id}/comments", ['body' => 'Secret text'])->assertCreated();
        $this->actingAs($this->u['client'])->postJson("/api/tickets/{$this->ticket->id}/comments", ['body' => 'Client says hi'])->assertCreated();

        $logs = EmployeeActivityLog::orderBy('id')->get();
        $this->assertSame(['ticket_status_changed', 'project_status_changed', 'ticket_comment_added'], $logs->pluck('event_type')->all());
        $this->assertSame(['todo' => 'in_progress'], [$logs[0]->metadata['from'] => $logs[0]->metadata['to']]);
        $this->assertSame($this->ticket->id, $logs[0]->ticket_id);
        $this->assertStringNotContainsString('Secret text', json_encode($logs[2]->metadata));
    }

    public function test_heartbeat_counts_active_and_idle_time_measured_by_the_server(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
        $this->postJson('/api/auth/login', ['email' => $this->u['employee']->email, 'password' => 'Passw0rd!Passw0rd'])->assertOk();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(60));
        $this->postJson('/api/employee/heartbeat', ['idle' => false])->assertOk()->assertJsonPath('idle_after_minutes', 5);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(60));
        $this->postJson('/api/employee/heartbeat', ['idle' => true])->assertOk();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(60));
        $this->postJson('/api/employee/heartbeat', ['idle' => true])->assertOk();

        $session = WorkSession::first();
        $this->assertSame(60, $session->active_seconds);
        $this->assertSame(120, $session->idle_seconds);
        $this->assertSame(1, EmployeeActivityLog::where('event_type', 'idle_detected')->count());
    }

    public function test_a_long_gap_is_capped_and_a_dead_session_is_closed_and_replaced(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
        $this->postJson('/api/auth/login', ['email' => $this->u['employee']->email, 'password' => 'Passw0rd!Passw0rd'])->assertOk();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(100));
        $this->postJson('/api/employee/heartbeat')->assertOk();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(3));
        $this->postJson('/api/employee/heartbeat')->assertOk();

        $sessions = WorkSession::orderBy('id')->get();
        $this->assertCount(2, $sessions);
        $this->assertSame('timeout', $sessions[0]->ended_reason);
        $this->assertSame(100, $sessions[0]->active_seconds);
        $this->assertNull($sessions[1]->ended_at);
        $this->assertSame(0, $sessions[1]->active_seconds);
    }

    public function test_stale_sessions_are_closed_by_the_maintenance_command(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
        $this->postJson('/api/auth/login', ['email' => $this->u['employee']->email, 'password' => 'Passw0rd!Passw0rd'])->assertOk();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(30));

        $this->artisan('activity:maintain')->assertSuccessful();

        $this->assertSame('timeout', WorkSession::first()->ended_reason);
        $this->assertContains('session_end', $this->types());
    }

    public function test_only_employees_can_send_heartbeats(): void
    {
        $this->app['auth']->forgetGuards(); // setUp signed someone in
        $this->postJson('/api/employee/heartbeat')->assertUnauthorized();
        foreach (['owner', 'client', 'admin', 'lead'] as $who) {
            $this->actingAs($this->u[$who])->postJson('/api/employee/heartbeat')->assertForbidden();
        }
    }

    public function test_heartbeat_is_throttled(): void
    {
        $this->actingAs($this->u['employee']);
        foreach (range(1, 6) as $ignored) {
            $this->postJson('/api/employee/heartbeat')->assertOk();
        }
        $this->postJson('/api/employee/heartbeat')->assertStatus(429);
    }

    public function test_activity_endpoints_are_admin_only(): void
    {
        $id = $this->u['employee']->id;
        foreach (['employee', 'owner', 'client', 'lead'] as $who) {
            $this->actingAs($this->u[$who])->getJson("/api/admin/employees/{$id}/activity")->assertForbidden();
            $this->actingAs($this->u[$who])->getJson("/api/admin/employees/{$id}/performance")->assertForbidden();
        }
        $this->actingAs($this->u['admin'])->getJson("/api/admin/employees/{$this->u['owner']->id}/activity")->assertNotFound();
    }

    public function test_activity_is_paginated_and_filtered_by_range_and_type(): void
    {
        $this->transition('employee', $this->ticket, 'start');
        foreach (range(1, 3) as $n) {
            EmployeeActivityLog::create(['employee_id' => $this->u['employee']->id, 'event_type' => 'login', 'occurred_at' => now()->subDays(40 + $n)]);
        }

        $all = $this->actingAs($this->u['admin'])->getJson("/api/admin/employees/{$this->u['employee']->id}/activity?from=".now()->subDays(60)->toDateString().'&per_page=2')->assertOk();
        $all->assertJsonPath('pagination.total', 5)->assertJsonCount(2, 'events')->assertJsonPath('pagination.last_page', 3);

        $recent = $this->actingAs($this->u['admin'])->getJson("/api/admin/employees/{$this->u['employee']->id}/activity")->assertOk();
        $recent->assertJsonPath('pagination.total', 2)->assertJsonPath('events.0.ticket.key', $this->ticket->key);

        $typed = $this->actingAs($this->u['admin'])->getJson("/api/admin/employees/{$this->u['employee']->id}/activity?event_type=ticket_status_changed")->assertOk();
        $typed->assertJsonPath('pagination.total', 1);
        $this->actingAs($this->u['admin'])->getJson("/api/admin/employees/{$this->u['employee']->id}/activity?event_type=nonsense")->assertUnprocessable();
    }

    public function test_performance_combines_sessions_time_schedule_and_logins(): void
    {
        $employee = $this->u['employee'];
        $day = CarbonImmutable::now(config('app.timezone'))->subDays(2)->startOfDay();
        WorkSession::create(['employee_id' => $employee->id, 'started_at' => $day->setTime(9, 40), 'last_heartbeat_at' => $day->setTime(12, 0), 'ended_at' => $day->setTime(12, 0), 'active_seconds' => 7200, 'idle_seconds' => 1800]);
        EmployeeActivityLog::create(['employee_id' => $employee->id, 'event_type' => 'login', 'occurred_at' => $day->setTime(9, 40)]);
        $early = $day->addDay();
        EmployeeActivityLog::create(['employee_id' => $employee->id, 'event_type' => 'login', 'occurred_at' => $early->setTime(8, 0)]);
        TimeEntry::create(['ticket_id' => $this->ticket->id, 'work_item_id' => $this->project->id, 'user_id' => $employee->id, 'entry_date' => $day->toDateString(), 'minutes' => 90, 'source' => 'manual']);

        $this->transition('employee', $this->ticket, 'start');
        $this->transition('employee', $this->ticket, 'send_to_review');
        $this->transition('client', $this->ticket, 'approve');
        $this->ticket->allocations()->create(['work_date' => $day->toDateString(), 'hours' => 3]);

        $response = $this->actingAs($this->u['admin'])->getJson("/api/admin/employees/{$employee->id}/performance?from={$day->toDateString()}&to=".now()->toDateString())->assertOk();
        $row = collect($response->json('days'))->firstWhere('date', $day->toDateString());

        $this->assertSame(2.0, (float) $row['active_hours']);
        $this->assertSame(0.5, (float) $row['idle_hours']);
        $this->assertSame(1.5, (float) $row['logged_hours']);
        $this->assertGreaterThanOrEqual(3.0, (float) $row['scheduled_hours']);
        $this->assertSame('late', $row['login_status']);
        $this->assertSame(40, $row['login_offset_minutes']);
        $this->assertSame('early', collect($response->json('days'))->firstWhere('date', $early->toDateString())['login_status']);
        $response->assertJsonPath('totals.tickets_completed', 1)->assertJsonPath('totals.late_logins', 1)->assertJsonPath('totals.early_logins', 1);
        $this->assertSame(1.5, (float) $response->json('totals.avg_logged_hours_per_ticket'));
        $this->assertNotEmpty($response->json('weeks'));
    }

    public function test_range_is_limited(): void
    {
        $this->actingAs($this->u['admin'])->getJson("/api/admin/employees/{$this->u['employee']->id}/performance?from=2020-01-01&to=2026-01-01")->assertUnprocessable();
    }
}
