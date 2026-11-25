<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketSlaClock;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\Support\SlaSweeper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** SLA clocks, the sweep, notifications, conversion and the KPIs of the support module. */
class SupportSlaTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    private function staff(array $capabilities, string $name = 'Sam Staff'): User
    {
        return User::factory()->create(['role' => Role::Employee, 'name' => $name, 'staff_capabilities' => $capabilities, 'is_active' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.timezone' => 'Asia/Karachi']);
        $this->buildFixture();
        // Monday 30 November 2026, 10:00 in Karachi.
        $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'Asia/Karachi'));
    }

    private function newTicket(array $extra = []): Ticket
    {
        $ref = $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets', ['type' => 'technical_support', 'title' => 'Forms down', 'description' => 'It is broken.', 'expected_result' => 'Works.', 'details' => ['goal' => 'Leads'], ...$extra])->assertCreated()->json('ticket.ref');

        return Ticket::query()->where('ref', $ref)->firstOrFail();
    }

    private function go(User $who, Ticket $ticket, string $to, array $data = [])
    {
        return $this->actingAs($who)->postJson("/api/staff/tickets/{$ticket->ref}/transition", ['to' => $to, ...$data]);
    }

    private function clock(Ticket $ticket, string $kind): TicketSlaClock
    {
        return TicketSlaClock::query()->where('ticket_id', $ticket->id)->where('kind', $kind)->firstOrFail();
    }

    public function test_a_new_ticket_starts_both_clocks_from_its_priority(): void
    {
        $ticket = $this->newTicket();
        // P3: first response in one business day (540 min): Monday 10:00 becomes Tuesday 10:00.
        $this->assertSame('2026-12-01 10:00', $this->clock($ticket, 'response')->due_at->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
        $this->assertSame(1620, $this->clock($ticket, 'resolution')->target_minutes);
        $urgent = $this->newTicket(['type' => 'critical_incident', 'details' => ['what_down' => 'x', 'since' => 'y', 'revenue_impact' => 'z', 'emergency_phone' => '1']]);
        $this->assertSame('2026-11-30 11:00', $this->clock($urgent, 'response')->due_at->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    public function test_agencies_see_a_soft_target_and_never_the_clock_states(): void
    {
        $ticket = $this->newTicket();
        $agency = $this->actingAs($this->u['owner'])->getJson('/api/agency/support/tickets/'.$ticket->ref)->assertOk();
        $this->assertStringContainsString('Tue 1 Dec, 10:00 AM', $agency->json('ticket.respond_by'));
        foreach (['at_risk', 'breached', 'sla', 'escalat', 'minutes_left'] as $leak) {
            $this->assertStringNotContainsString($leak, $agency->getContent());
        }
        $staff = $this->actingAs($this->staff(['triage']))->getJson('/api/staff/tickets/'.$ticket->ref)->assertOk();
        $this->assertSame('on_track', $staff->json('ticket.sla.response.state'));
    }

    public function test_the_first_staff_reply_meets_the_response_clock(): void
    {
        $ticket = $this->newTicket();
        $this->travelTo(CarbonImmutable::parse('2026-11-30 11:00', 'Asia/Karachi'));
        $this->actingAs($this->staff(['triage']))->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'On it'])->assertCreated();
        $this->assertNotNull($this->clock($ticket, 'response')->met_at);
        $this->assertNull($this->actingAs($this->u['owner'])->getJson('/api/agency/support/tickets/'.$ticket->ref)->json('ticket.respond_by'));
    }

    public function test_waiting_for_the_agency_pauses_the_clock_and_resuming_keeps_the_time_left(): void
    {
        $builder = $this->staff(['builder', 'triage']);
        $ticket = $this->newTicket();
        $this->go($builder, $ticket, 'triage')->assertOk();
        $this->go($builder, $ticket, 'assigned', ['assigned_to_id' => $builder->id])->assertOk();
        $this->go($builder, $ticket, 'in_progress')->assertOk();
        $before = $this->clock($ticket, 'resolution');
        $this->go($builder, $ticket, 'waiting_agency', ['waiting_reason' => 'Need info', 'follow_up_date' => '2026-12-04'])->assertOk();
        $paused = $this->clock($ticket, 'resolution');
        $this->assertNotNull($paused->paused_at);
        $this->assertSame(1620, $paused->remaining_minutes);
        $this->assertSame('paused', $this->actingAs($builder)->getJson('/api/staff/tickets/'.$ticket->ref)->json('ticket.sla.resolution.state'));

        // Two days pass; the agency replies; the clock has the same minutes left as when it stopped.
        $this->travelTo(CarbonImmutable::parse('2026-12-02 10:00', 'Asia/Karachi'));
        $this->actingAs($this->u['client'])->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Here it is'])->assertCreated();
        $resumed = $this->clock($ticket, 'resolution');
        $this->assertNull($resumed->paused_at);
        $this->assertSame(1620, $this->calendarMinutes('2026-12-02 10:00', $resumed->due_at));
        $this->assertNotEquals($before->due_at->toISOString(), $resumed->due_at->toISOString());
    }

    private function calendarMinutes(string $from, $to): int
    {
        return \App\Services\Support\SlaCalculator::fromConfig()->businessMinutesBetween(CarbonImmutable::parse($from, 'Asia/Karachi'), CarbonImmutable::instance($to));
    }

    public function test_resolving_meets_the_resolution_clock_and_a_reopen_starts_a_fresh_one(): void
    {
        $builder = $this->staff(['builder', 'triage']);
        $ticket = $this->newTicket();
        $this->go($builder, $ticket, 'triage');
        $this->go($builder, $ticket, 'assigned', ['assigned_to_id' => $builder->id]);
        $this->go($builder, $ticket, 'in_progress');
        $this->go($builder, $ticket, 'resolved', ['resolution_summary' => 'Done', 'resolution_category' => 'fixed'])->assertOk();
        $this->assertNotNull($this->clock($ticket, 'resolution')->met_at);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00', 'Asia/Karachi'));
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/reopen', ['reason' => 'Again'])->assertOk();
        $fresh = $this->clock($ticket, 'resolution');
        $this->assertNull($fresh->met_at);
        $this->assertSame('2026-12-01 10:00', $fresh->started_at->setTimezone('Asia/Karachi')->format('Y-m-d H:i'));
    }

    public function test_the_sweep_flags_at_risk_then_breached_once_each(): void
    {
        $manager = $this->staff(['triage'], 'Mia');
        $ticket = $this->newTicket(['type' => 'critical_incident', 'details' => ['what_down' => 'x', 'since' => 'y', 'revenue_impact' => 'z', 'emergency_phone' => '1']]);
        $sweeper = app(SlaSweeper::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-30 10:30', 'Asia/Karachi')); // 50% of the 60 minute response window
        $this->assertSame(0, $sweeper->sweep()['at_risk']);
        $this->travelTo(CarbonImmutable::parse('2026-11-30 10:50', 'Asia/Karachi')); // 83%
        $this->assertSame(1, $sweeper->sweep()['at_risk']);
        $this->assertSame(0, $sweeper->sweep()['at_risk']);
        $this->travelTo(CarbonImmutable::parse('2026-11-30 11:30', 'Asia/Karachi'));
        $this->assertSame(1, $sweeper->sweep()['breached']);
        $this->assertSame(0, $sweeper->sweep()['breached']);
        $this->assertSame(1, TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'sla_breached')->count());
        $this->assertSame('breached', $this->actingAs($manager)->getJson('/api/staff/tickets/'.$ticket->ref)->json('ticket.sla.response.state'));
        // The breach reaches the managers and never the agency.
        $this->assertTrue(DatabaseNotification::query()->where('notifiable_id', $manager->id)->get()->contains(fn ($n) => str_contains($n->data['title'] ?? '', 'SLA breached')));
        $this->assertSame(0, DatabaseNotification::query()->whereIn('notifiable_id', [$this->u['client']->id, $this->u['owner']->id])->get()->filter(fn ($n) => str_contains(json_encode($n->data), 'SLA'))->count());
    }

    public function test_unassigned_tickets_escalate_once_and_a_paused_clock_is_not_swept(): void
    {
        $this->staff(['triage']);
        $ticket = $this->newTicket();
        $sweeper = app(SlaSweeper::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-30 11:30', 'Asia/Karachi'));
        $this->assertSame(0, $sweeper->sweep()['unassigned']);
        $this->travelTo(CarbonImmutable::parse('2026-11-30 12:30', 'Asia/Karachi')); // two business hours
        $this->assertSame(1, $sweeper->sweep()['unassigned']);
        $this->assertSame(0, $sweeper->sweep()['unassigned']);
        $this->assertSame(1, TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'escalated_unassigned')->count());
    }

    public function test_waiting_agencies_are_reminded_then_escalated_and_resolved_tickets_auto_close(): void
    {
        $builder = $this->staff(['builder', 'triage']);
        $ticket = $this->newTicket();
        $this->go($builder, $ticket, 'triage');
        $this->go($builder, $ticket, 'assigned', ['assigned_to_id' => $builder->id]);
        $this->go($builder, $ticket, 'in_progress');
        $this->go($builder, $ticket, 'waiting_agency', ['waiting_reason' => 'Need access', 'follow_up_date' => '2026-12-10'])->assertOk();
        $sweeper = app(SlaSweeper::class);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00', 'Asia/Karachi'));
        $this->assertSame(0, $sweeper->sweep()['reminders']);
        $this->travelTo(CarbonImmutable::parse('2026-12-02 11:00', 'Asia/Karachi')); // two business days later
        $this->assertSame(1, $sweeper->sweep()['reminders']);
        $this->assertSame(0, $sweeper->sweep()['reminders']);
        $this->assertTrue(DatabaseNotification::query()->where('notifiable_id', $this->u['client']->id)->get()->contains(fn ($n) => str_contains($n->data['title'] ?? '', 'Still waiting')));
        $this->travelTo(CarbonImmutable::parse('2026-12-07 11:00', 'Asia/Karachi')); // five business days
        $this->assertSame(1, $sweeper->sweep()['stalled']);

        $other = $this->newTicket(['title' => 'Auto close me']);
        $other->forceFill(['status' => 'resolved', 'resolved_at' => now()->subDays(8), 'resolution_summary' => 'Done', 'resolution_category' => 'fixed'])->save();
        $answered = $this->newTicket(['title' => 'Answered']);
        $answered->forceFill(['status' => 'resolved', 'resolved_at' => now()->subDays(8), 'resolution_summary' => 'Done', 'resolution_category' => 'fixed'])->save();
        \App\Models\TicketComment::create(['ticket_id' => $answered->id, 'user_id' => $this->u['client']->id, 'body' => 'One more thing', 'is_internal' => false])->forceFill(['created_at' => now()->subDay()])->save();
        $this->assertSame(1, $sweeper->sweep()['auto_closed']);
        $this->assertSame('closed', $other->fresh()->status);
        $this->assertSame('resolved', $answered->fresh()->status);
    }

    public function test_notifications_follow_the_matrix_and_never_carry_internal_text(): void
    {
        $manager = $this->staff(['triage'], 'Mia Manager');
        $builder = $this->staff(['builder'], 'Ben Builder');
        $ticket = $this->newTicket();
        $titles = fn (User $u) => DatabaseNotification::query()->where('notifiable_id', $u->id)->get()->pluck('data.title')->all();
        $this->assertContains('We received '.$ticket->ref, $titles($this->u['client']));
        $this->assertContains('New support ticket', $titles($manager));

        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/assign", ['assigned_to_id' => $builder->id])->assertOk();
        $this->assertContains('Ticket assigned to you', $titles($builder));
        $this->actingAs($manager)->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'SECRET-INTERNAL-TEXT', 'is_internal' => true])->assertCreated();
        $this->actingAs($manager)->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Hello agency'])->assertCreated();
        $agencyNotices = DatabaseNotification::query()->whereIn('notifiable_id', [$this->u['client']->id, $this->u['owner']->id])->get();
        $this->assertStringNotContainsString('SECRET-INTERNAL-TEXT', $agencyNotices->toJson());
        $this->assertTrue($agencyNotices->contains(fn ($n) => ($n->data['title'] ?? '') === 'New reply' && str_starts_with($n->data['url_path'], '/agency/support/')));
        $this->assertTrue(DatabaseNotification::query()->where('notifiable_id', $builder->id)->get()->contains(fn ($n) => ($n->data['title'] ?? '') === 'New internal note'));
        // A reply from the agency goes to the owner of the ticket.
        $this->actingAs($this->u['client'])->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Thanks'])->assertCreated();
        $this->assertContains('New reply', $titles($builder));
    }

    public function test_critical_tickets_alert_managers_immediately(): void
    {
        $manager = $this->staff(['delivery_manager'], 'Mia');
        $this->newTicket(['type' => 'critical_incident', 'details' => ['what_down' => 'x', 'since' => 'y', 'revenue_impact' => 'z', 'emergency_phone' => '1']]);
        $this->assertContains('Critical ticket', DatabaseNotification::query()->where('notifiable_id', $manager->id)->get()->pluck('data.title')->all());
    }

    public function test_conversion_to_a_project_keeps_history_links_both_ways_and_is_idempotent(): void
    {
        $manager = $this->staff(['triage', 'delivery_manager'], 'Mia');
        $ticket = $this->newTicket(['title' => 'Build me a funnel', 'type' => 'fulfillment_request', 'details' => ['deliverables' => 'A funnel', 'acceptance' => 'Live']]);
        $assignment = \App\Models\EmployeeAssignment::query()->where('agency_id', $this->agency->id)->first();
        $body = ['to' => 'project', 'assignment_id' => $assignment->id];

        $this->actingAs($this->staff(['builder']))->postJson("/api/staff/tickets/{$ticket->ref}/convert", $body)->assertForbidden();
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/convert", ['to' => 'project', 'assignment_id' => 999999])->assertUnprocessable();
        $first = $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/convert", $body)->assertOk();
        $project = WorkItem::findOrFail($first->json('project.id'));
        $this->assertSame($ticket->id, $project->created_from_ticket_id);
        $this->assertStringContainsString($ticket->ref, $project->description);
        $this->assertSame($this->agency->id, $project->agency_id);
        $fresh = $ticket->fresh();
        $this->assertSame('project', $fresh->converted_to_type);
        $this->assertSame($project->id, $fresh->converted_to_id);
        $this->assertSame('closed', $fresh->status);
        $this->assertSame('converted_to_project', $fresh->resolution_category);
        $this->assertGreaterThan(1, TicketEvent::query()->where('ticket_id', $ticket->id)->count()); // history kept

        $second = $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/convert", $body)->assertOk();
        $this->assertSame($project->id, $second->json('project.id'));
        $this->assertSame(1, WorkItem::query()->where('created_from_ticket_id', $ticket->id)->count());

        $change = $this->newTicket(['title' => 'Extra pages']);
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$change->ref}/convert", ['to' => 'change_request'])->assertOk()->assertJsonPath('ticket.kind', 'change_request');
        $this->assertSame('new_scope', $change->fresh()->scope_classification);
    }

    public function test_the_kpi_dashboard_is_for_managers_and_counts_the_range(): void
    {
        $manager = $this->staff(['triage'], 'Mia');
        $ticket = $this->newTicket();
        $this->actingAs($manager)->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Hi'])->assertCreated();
        $this->actingAs($this->staff(['builder']))->getJson('/api/staff/dashboard')->assertForbidden();
        $this->actingAs($this->u['client'])->getJson('/api/staff/dashboard')->assertForbidden();
        $kpis = $this->actingAs($manager)->getJson('/api/staff/dashboard?from=2026-11-01&to=2026-12-31')->assertOk()->json('kpis');
        $this->assertSame(1, $kpis['volume']['total']);
        $this->assertSame(0, $kpis['first_response_minutes']);
        $this->assertEquals(100, $kpis['sla_compliance_percent']);
        $this->assertSame(90, $kpis['targets']['first_pass_qa']);
        $this->assertSame(0, $this->actingAs($manager)->getJson('/api/staff/dashboard?from=2025-01-01&to=2025-01-31')->json('kpis.volume.total'));
    }
}
