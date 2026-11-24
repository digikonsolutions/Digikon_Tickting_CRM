<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BrowserHistoryEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Services\MonitoringPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/**
 * Browser history is only kept while the employee has work in progress, and each record is linked to that work by
 * the server (from the ticket's status history), never by what the extension claims.
 */
class BrowserHistoryWorkLinkTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scheduling.require_timeline_review' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-12-09 10:00', config('app.timezone')));
        $this->buildFixture();
        app(MonitoringPolicy::class)->accept($this->u['employee'], null);
        $this->ticket = $this->ticket('Build header');
    }

    private function ticket(string $title): Ticket
    {
        static $number = 900;

        return Ticket::create([
            'agency_id' => $this->agency->id, 'work_item_id' => $this->project->id, 'created_by' => $this->u['client']->id, 'number' => ++$number,
            'title' => $title, 'description' => 'x', 'estimated_hours' => 2, 'priority' => 'normal', 'status' => 'todo', 'position' => $number,
        ]);
    }

    private function event(string $id, CarbonImmutable $at, array $extra = []): array
    {
        return ['client_event_id' => $id, 'event_type' => 'navigated', 'tab_session_id' => 't1', 'url' => "https://site.test/{$id}", 'title' => $id, 'occurred_at' => $at->toIso8601String(), ...$extra];
    }

    private function transition(Ticket $ticket, string $action): void
    {
        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$ticket->id}/transitions", ['action' => $action])->assertOk();
    }

    public function test_only_records_from_while_the_ticket_is_in_progress_are_kept_and_linked_to_it(): void
    {
        $before = now()->toImmutable()->subMinutes(5);
        $this->travel(1)->minutes();
        $this->transition($this->ticket, 'start');
        $this->travel(10)->minutes();
        $during = now()->toImmutable()->subMinutes(5);
        $this->transition($this->ticket, 'send_to_review');
        $this->travel(5)->minutes();
        $after = now()->toImmutable()->subMinute();

        $this->actingAs($this->u['employee'])->postJson('/api/browser-history/sync', ['events' => [
            $this->event('before', $before), $this->event('during-1', $during), $this->event('during-2', $during->addSecond()), $this->event('after', $after),
        ]])->assertOk()->assertJsonPath('accepted', 2)->assertJsonPath('outside_work', 2);

        $this->assertSame(['during-1', 'during-2'], BrowserHistoryEvent::query()->orderBy('id')->pluck('client_event_id')->all());
        $this->assertSame([$this->ticket->id], BrowserHistoryEvent::query()->distinct()->pluck('ticket_id')->all());
        $this->assertSame([$this->project->id], BrowserHistoryEvent::query()->distinct()->pluck('work_item_id')->all());
    }

    public function test_the_extension_is_told_when_to_record_and_for_which_ticket(): void
    {
        $this->actingAs($this->u['employee'])->getJson('/api/browser-history/status')->assertOk()
            ->assertJsonPath('tracking_allowed', true)->assertJsonPath('recording', false)->assertJsonPath('active_work', null);

        $this->transition($this->ticket, 'start');
        $this->getJson('/api/browser-history/status')->assertJsonPath('recording', true)
            ->assertJsonPath('active_work.ticket_id', $this->ticket->id)
            ->assertJsonPath('active_work.ticket_key', $this->ticket->fresh()->key)
            ->assertJsonPath('active_work.project_title', 'Project');

        $this->transition($this->ticket, 'send_to_review');
        $this->getJson('/api/browser-history/status')->assertJsonPath('recording', false)->assertJsonPath('active_work', null);
    }

    public function test_without_consent_nothing_is_recorded_even_during_work(): void
    {
        $other = User::factory()->create(['role' => Role::Employee]);
        $this->project->update(['employee_id' => $other->id]);
        $this->actingAs($other)->getJson('/api/browser-history/status')->assertJsonPath('tracking_allowed', false)->assertJsonPath('recording', false);
    }

    public function test_the_most_recently_started_ticket_wins_and_a_claimed_ticket_id_is_ignored(): void
    {
        $second = $this->ticket('Second');
        $this->transition($this->ticket, 'start');
        $this->travel(2)->minutes();
        $this->transition($second, 'start');
        $this->travel(1)->minutes();

        $this->actingAs($this->u['employee'])->postJson('/api/browser-history/sync', ['events' => [
            $this->event('x', now()->toImmutable()->subSeconds(10), ['ticket_id' => $this->ticket->id]),
        ]])->assertOk()->assertJsonPath('accepted', 1);

        $this->assertSame($second->id, BrowserHistoryEvent::query()->sole()->ticket_id);
    }

    public function test_a_project_worked_without_tickets_is_linked_to_the_project(): void
    {
        $this->ticket->delete();
        $this->actingAs($this->u['employee'])->postJson("/api/work-items/{$this->project->id}/start")->assertOk();
        $this->travel(1)->minutes();

        $this->getJson('/api/browser-history/status')->assertJsonPath('recording', true)->assertJsonPath('active_work.ticket_id', null)->assertJsonPath('active_work.work_item_id', $this->project->id);
        $this->postJson('/api/browser-history/sync', ['events' => [$this->event('p', now()->toImmutable()->subSeconds(5))]])->assertJsonPath('accepted', 1);
        $row = BrowserHistoryEvent::query()->sole();
        $this->assertNull($row->ticket_id);
        $this->assertSame($this->project->id, $row->work_item_id);
    }

    public function test_the_employee_sees_whether_the_extension_is_connected_and_recording(): void
    {
        $this->actingAs($this->u['employee'])->getJson('/api/browser-history/me')->assertOk()
            ->assertJsonPath('consented', true)->assertJsonPath('extension_connected', false)->assertJsonPath('recording', false);

        $this->transition($this->ticket, 'start');
        $this->getJson('/api/browser-history/me')->assertJsonPath('active_work.ticket_id', $this->ticket->id)->assertJsonPath('recording', false);

        $this->getJson('/api/browser-history/status')->assertOk(); // the extension checks in
        $this->getJson('/api/browser-history/me')->assertJsonPath('extension_connected', true)->assertJsonPath('recording', true)->assertJsonPath('retention_days', 365);

        $this->travel(4)->minutes();
        $this->getJson('/api/browser-history/me')->assertJsonPath('extension_connected', false)->assertJsonPath('recording', false);
        $this->actingAs($this->u['client'])->getJson('/api/browser-history/me')->assertForbidden();
    }

    public function test_admin_sees_the_ticket_on_each_record_and_can_filter_by_it(): void
    {
        $this->transition($this->ticket, 'start');
        $this->travel(1)->minutes();
        $this->actingAs($this->u['employee'])->postJson('/api/browser-history/sync', ['events' => [$this->event('a', now()->toImmutable()->subSeconds(30)), $this->event('b', now()->toImmutable()->subSeconds(20))]])->assertJsonPath('accepted', 2);

        $this->actingAs($this->u['admin'])->getJson("/api/admin/browser-history/events?ticket_id={$this->ticket->id}")->assertOk()
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.ticket.id', $this->ticket->id)
            ->assertJsonPath('events.0.ticket.key', $this->ticket->fresh()->key)
            ->assertJsonPath('events.0.project.title', 'Project');
        $this->getJson('/api/admin/browser-history/events?ticket_id=999999')->assertJsonCount(0, 'events');
        $this->getJson("/api/admin/browser-history/summary?ticket_id={$this->ticket->id}")->assertJsonPath('totals.visits', 2);
    }

    public function test_work_only_can_be_switched_off_by_configuration(): void
    {
        config(['activity.browser_history_work_only' => false]);
        $this->actingAs($this->u['employee'])->getJson('/api/browser-history/status')->assertJsonPath('recording', true);
        $this->postJson('/api/browser-history/sync', ['events' => [$this->event('free', now()->toImmutable()->subMinute())]])->assertJsonPath('accepted', 1);
        $this->assertNull(BrowserHistoryEvent::query()->sole()->ticket_id);
    }
}
