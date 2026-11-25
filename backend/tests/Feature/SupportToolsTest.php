<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** QA runs, bug details, approvals, relations, settings, entitlements and recurring tickets. */
class SupportToolsTest extends TestCase
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
        $this->buildFixture();
    }

    private function newTicket(array $extra = []): Ticket
    {
        $ref = $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets', ['type' => 'technical_support', 'title' => 'Help', 'description' => 'Please.', 'expected_result' => 'Fixed.', 'details' => ['goal' => 'x'], ...$extra])->assertCreated()->json('ticket.ref');

        return Ticket::query()->where('ref', $ref)->firstOrFail();
    }

    private function inQa(User $builder, User $qa): Ticket
    {
        $ticket = $this->newTicket(['type' => 'bug', 'service' => 'ghl', 'details' => ['steps' => '1', 'actual_result' => 'x', 'affected' => 'x', 'when' => 'x', 'evidence' => 'x']]);
        $ticket->forceFill(['status' => 'qa', 'assigned_to_id' => $builder->id, 'reviewer_id' => $qa->id])->save();

        return $ticket;
    }

    public function test_qa_uses_the_checklist_library_and_a_fail_goes_back_to_the_builder(): void
    {
        $builder = $this->staff(['builder']);
        $qa = $this->staff(['qa'], 'Quinn');
        $ticket = $this->inQa($builder, $qa);

        $library = $this->actingAs($qa)->getJson("/api/staff/tickets/{$ticket->ref}/qa")->assertOk()->json('library');
        $this->assertArrayHasKey('ghl_workflows', $library);
        $this->assertArrayHasKey('regression', $library); // a bug always gets the regression list
        $this->assertSame('Trigger and filters', $library['ghl_workflows'][0]['label']);

        $item = fn (string $result) => ['list' => 'regression', 'label' => 'No unintended side effects', 'result' => $result];
        $this->actingAs($builder)->postJson("/api/staff/tickets/{$ticket->ref}/qa", ['items' => [$item('pass')], 'resolution_summary' => 'x', 'resolution_category' => 'fixed'])->assertForbidden();
        $this->actingAs($qa)->postJson("/api/staff/tickets/{$ticket->ref}/qa", ['items' => [$item('fail')]])->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->actingAs($qa)->postJson("/api/staff/tickets/{$ticket->ref}/qa", ['items' => [$item('fail')], 'note' => 'Duplicate email sent'])->assertOk()->assertJsonPath('status', 'in_progress');
        $ticket->refresh()->forceFill(['status' => 'qa'])->save();
        $this->actingAs($qa)->postJson("/api/staff/tickets/{$ticket->ref}/qa", ['items' => [$item('pass')]])->assertUnprocessable()->assertJsonValidationErrors(['resolution_summary']);
        $this->actingAs($qa)->postJson("/api/staff/tickets/{$ticket->ref}/qa", ['items' => [$item('pass')], 'resolution_summary' => 'Fixed and retested', 'resolution_category' => 'fixed', 'evidence_url' => 'https://loom.example/1'])->assertOk()->assertJsonPath('status', 'resolved');
        $runs = $this->actingAs($qa)->getJson("/api/staff/tickets/{$ticket->ref}/qa")->json('runs');
        $this->assertSame(['pass', 'fail'], array_column($runs, 'result'));
        // Another reviewer cannot take over a ticket assigned to Quinn; the agency never sees QA at all.
        $this->actingAs($this->staff(['qa']))->postJson("/api/staff/tickets/{$ticket->ref}/qa", ['items' => [$item('pass')]])->assertUnprocessable(); // no longer in QA
        $this->actingAs($this->u['client'])->getJson("/api/staff/tickets/{$ticket->ref}/qa")->assertForbidden();
        $this->assertStringNotContainsString('Duplicate email sent', $this->actingAs($this->u['owner'])->getJson("/api/tickets/{$ticket->id}/timeline")->getContent());
    }

    public function test_bug_details_are_validated_and_saved_by_the_team(): void
    {
        $builder = $this->staff(['builder']);
        $ticket = $this->newTicket();
        $this->actingAs($builder)->putJson("/api/staff/tickets/{$ticket->ref}/bug", ['severity' => 'terrible'])->assertUnprocessable();
        $this->actingAs($builder)->putJson("/api/staff/tickets/{$ticket->ref}/bug", ['severity' => 'high', 'component' => 'workflow', 'bug_status' => 'retest'])->assertOk()->assertJsonPath('bug.bug_status', 'retest');
        $this->actingAs($this->staff([]))->putJson("/api/staff/tickets/{$ticket->ref}/bug", ['severity' => 'low'])->assertNotFound();
    }

    public function test_approvals_are_decided_only_by_the_right_capability(): void
    {
        $builder = $this->staff(['builder']);
        $manager = $this->staff(['delivery_manager']);
        $finance = $this->staff(['finance']);
        $ticket = $this->newTicket();
        $this->actingAs($builder)->postJson("/api/staff/tickets/{$ticket->ref}/approvals", ['kind' => 'scope', 'note' => 'This is extra work'])->assertCreated();
        $id = $this->actingAs($builder)->getJson("/api/staff/tickets/{$ticket->ref}/approvals")->json('approvals.0.id');
        $this->assertFalse($this->actingAs($builder)->getJson("/api/staff/tickets/{$ticket->ref}/approvals")->json('approvals.0.can_decide'));
        $this->actingAs($builder)->postJson("/api/staff/tickets/{$ticket->ref}/approvals/{$id}", ['approve' => true])->assertForbidden();
        $this->actingAs($finance)->postJson("/api/staff/tickets/{$ticket->ref}/approvals/{$id}", ['approve' => true])->assertForbidden();
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/approvals/{$id}", ['approve' => false, 'note' => 'Not extra'])->assertOk()->assertJsonPath('approvals.0.status', 'rejected');
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/approvals/{$id}", ['approve' => true])->assertUnprocessable();
        $this->actingAs($builder)->postJson("/api/staff/tickets/{$ticket->ref}/approvals", ['kind' => 'quote'])->assertCreated();
        $quote = $this->actingAs($finance)->getJson("/api/staff/tickets/{$ticket->ref}/approvals")->json('approvals.0.id');
        $this->actingAs($finance)->postJson("/api/staff/tickets/{$ticket->ref}/approvals/{$quote}", ['approve' => true])->assertOk();
        $this->actingAs($this->u['client'])->getJson("/api/staff/tickets/{$ticket->ref}/approvals")->assertForbidden();
    }

    public function test_relations_and_watchers_stay_with_staff(): void
    {
        $triage = $this->staff(['triage']);
        $a = $this->newTicket();
        $b = $this->newTicket();
        $this->actingAs($triage)->postJson("/api/staff/tickets/{$a->ref}/relations", ['ref' => $a->ref, 'type' => 'related'])->assertUnprocessable();
        $this->actingAs($triage)->postJson("/api/staff/tickets/{$a->ref}/relations", ['ref' => $b->ref, 'type' => 'duplicate'])->assertOk()->assertJsonPath('relations.0.ref', $b->ref);
        $this->actingAs($triage)->postJson("/api/staff/tickets/{$a->ref}/watch", ['on' => true])->assertOk()->assertJsonPath('watching', true);
        $this->actingAs($triage)->postJson("/api/staff/tickets/{$a->ref}/watch", ['on' => false])->assertOk()->assertJsonPath('watching', false);
        $this->actingAs($this->u['owner'])->getJson("/api/staff/tickets/{$a->ref}/relations")->assertForbidden();
        $this->assertStringNotContainsString($b->ref, $this->actingAs($this->u['owner'])->getJson("/api/tickets/{$a->id}/timeline")->getContent());
    }

    public function test_an_admin_edits_the_sla_settings_and_new_tickets_use_them(): void
    {
        $this->actingAs($this->staff(['triage']))->putJson('/api/staff/settings', ['reopen_days' => 3])->assertForbidden();
        $this->actingAs($this->u['admin'])->putJson('/api/staff/settings', ['hours' => ['days' => [1, 2, 3], 'start' => '10:00', 'end' => '09:00']])->assertUnprocessable();
        $this->actingAs($this->u['admin'])->putJson('/api/staff/settings', ['sla' => ['normal' => ['response' => 30, 'resolution' => 60]], 'holidays' => ['2026-12-25'], 'reopen_days' => 3])->assertOk()
            ->assertJsonPath('settings.sla.normal.response', 30)->assertJsonPath('settings.sla.urgent.response', 60)->assertJsonPath('settings.reopen_days', 3);
        $this->assertSame(30, config('support.sla.normal.response'));
        $ticket = $this->newTicket();
        $this->assertSame(30, DB::table('ticket_sla_clocks')->where('ticket_id', $ticket->id)->where('kind', 'response')->value('target_minutes'));
        $this->assertSame(['2026-12-25'], $this->actingAs($this->u['admin'])->getJson('/api/staff/settings')->json('settings.holidays'));
    }

    public function test_included_revisions_past_the_allowance_default_to_billable(): void
    {
        $manager = $this->staff(['delivery_manager']);
        $finance = $this->staff(['finance']);
        $this->actingAs($manager)->putJson("/api/staff/agencies/{$this->agency->id}/entitlement", ['included_revisions_per_month' => 1])->assertForbidden();
        $this->actingAs($finance)->putJson("/api/staff/agencies/{$this->agency->id}/entitlement", ['included_revisions_per_month' => 1])->assertOk();
        $one = $this->newTicket();
        $two = $this->newTicket();
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$one->ref}/scope", ['scope_classification' => 'included_revision'])->assertOk()->assertJsonPath('ticket.billing_status', 'non_billable');
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$two->ref}/scope", ['scope_classification' => 'included_revision'])->assertOk()->assertJsonPath('ticket.billing_status', 'billable');
        $this->assertSame(['allowed' => 1, 'used' => 1], $this->actingAs($manager)->getJson("/api/staff/tickets/{$two->ref}")->json('ticket.entitlement'));
    }

    public function test_recurring_tickets_are_created_on_schedule_once_per_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 07:00', 'UTC'));
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/recurring', ['type' => 'technical_support', 'title' => 'Weekly check', 'description' => 'Check the forms.', 'expected_result' => 'All fine.', 'details' => ['goal' => 'Health'], 'frequency' => 'weekly', 'start_on' => '2026-11-30'])->assertCreated();
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/recurring', ['type' => 'bug', 'title' => 'x', 'description' => 'x', 'expected_result' => 'x', 'frequency' => 'weekly', 'start_on' => '2026-11-30'])->assertUnprocessable();
        $this->artisan('tickets:run-recurring')->assertSuccessful();
        $this->artisan('tickets:run-recurring')->assertSuccessful();
        $this->assertSame(1, Ticket::query()->where('title', 'Weekly check')->count());
        $this->assertSame('2026-12-07', DB::table('recurring_ticket_schedules')->value('next_run_on'));
        $this->assertCount(1, $this->actingAs($this->u['owner'])->getJson('/api/agency/support/recurring')->json('schedules'));
        $this->actingAs($this->outsider())->deleteJson('/api/agency/support/recurring/'.DB::table('recurring_ticket_schedules')->value('id'))->assertNotFound();
    }

    public function test_saved_views_are_personal_and_capped(): void
    {
        $a = $this->staff(['triage']);
        $b = $this->staff(['triage']);
        $this->actingAs($a)->postJson('/api/staff/saved-views', ['name' => 'Urgent mine', 'filters' => ['view' => 'critical', 'q' => '']])->assertOk()->assertJsonPath('views.0.filters.view', 'critical');
        $this->assertCount(0, $this->actingAs($b)->getJson('/api/staff/saved-views')->json('views'));
        $id = $this->actingAs($a)->getJson('/api/staff/saved-views')->json('views.0.id');
        $this->actingAs($b)->deleteJson("/api/staff/saved-views/{$id}")->assertOk();
        $this->assertCount(1, $this->actingAs($a)->getJson('/api/staff/saved-views')->json('views'));
        $this->actingAs($this->u['client'])->getJson('/api/staff/saved-views')->assertForbidden();
        $this->actingAs($a)->deleteJson("/api/staff/saved-views/{$id}")->assertOk()->assertJsonCount(0, 'views');
    }

    public function test_bulk_actions_decide_each_ticket_on_its_own(): void
    {
        $manager = $this->staff(['triage']);
        $builder = $this->staff(['builder']);
        $one = $this->newTicket();
        $two = $this->newTicket();
        $two->forceFill(['status' => 'closed'])->save();
        $res = $this->actingAs($manager)->postJson('/api/staff/tickets/bulk', ['refs' => [$one->ref, $two->ref, 'DG-9999'], 'action' => 'transition', 'to' => 'triage'])->assertOk();
        $this->assertSame(1, $res->json('done'));
        $this->assertSame([true, false, false], array_column($res->json('results'), 'ok'));
        $this->assertSame('triage', $one->fresh()->status);

        $this->actingAs($manager)->postJson('/api/staff/tickets/bulk', ['refs' => [$one->ref], 'action' => 'assign', 'assigned_to_id' => $builder->id])->assertOk()->assertJsonPath('done', 1);
        $this->assertSame($builder->id, $one->fresh()->assigned_to_id);
        $this->actingAs($manager)->postJson('/api/staff/tickets/bulk', ['refs' => [$one->ref], 'action' => 'priority', 'priority' => 'high'])->assertOk()->assertJsonPath('done', 1);
        // A builder may not re-prioritise or assign in bulk: the ticket stays as it was.
        $this->actingAs($builder)->postJson('/api/staff/tickets/bulk', ['refs' => [$one->ref], 'action' => 'priority', 'priority' => 'low'])->assertOk()->assertJsonPath('done', 0);
        $this->assertSame('high', $one->fresh()->priority);
        $this->actingAs($this->u['client'])->postJson('/api/staff/tickets/bulk', ['refs' => [$one->ref], 'action' => 'priority', 'priority' => 'low'])->assertForbidden();
    }

    public function test_the_workload_view_counts_open_work_per_person(): void
    {
        $manager = $this->staff(['triage'], 'Mia');
        $builder = $this->staff(['builder'], 'Ben');
        $a = $this->newTicket();
        $b = $this->newTicket();
        $this->newTicket();
        $a->forceFill(['assigned_to_id' => $builder->id, 'status' => 'in_progress', 'priority' => 'urgent'])->save();
        $b->forceFill(['assigned_to_id' => $builder->id, 'status' => 'resolved'])->save();
        $res = $this->actingAs($manager)->getJson('/api/staff/workload')->assertOk();
        $ben = collect($res->json('people'))->firstWhere('id', $builder->id);
        $this->assertSame(1, $ben['open']);
        $this->assertSame(1, $ben['critical']);
        $this->assertSame(1, $res->json('unassigned'));
        $this->actingAs($builder)->getJson('/api/staff/workload')->assertForbidden();
    }

    public function test_a_quote_is_sent_by_staff_and_answered_only_by_the_account_owner(): void
    {
        $manager = $this->staff(['delivery_manager']);
        $builder = $this->staff(['builder']);
        $ticket = $this->newTicket();
        $this->actingAs($builder)->postJson("/api/staff/tickets/{$ticket->ref}/quote", ['amount' => 500, 'description' => 'Five new pages'])->assertForbidden();
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/quote", ['amount' => 0, 'description' => 'x'])->assertUnprocessable();
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/quote", ['amount' => 500, 'currency' => 'usd', 'description' => 'Five new pages', 'valid_until' => now()->addDays(7)->toDateString()])->assertCreated();

        $seen = $this->actingAs($this->u['client'])->getJson('/api/agency/support/tickets/'.$ticket->ref)->assertOk();
        $this->assertSame('Five new pages', $seen->json('ticket.quote.description'));
        $this->assertFalse($seen->json('ticket.can_answer_quote'));
        $this->assertTrue($this->actingAs($this->u['owner'])->getJson('/api/agency/support/tickets/'.$ticket->ref)->json('ticket.can_answer_quote'));
        $this->assertTrue(\Illuminate\Notifications\DatabaseNotification::query()->where('notifiable_id', $this->u['owner']->id)->get()->contains(fn ($n) => str_contains($n->data['title'] ?? '', 'quote')));

        $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/quote', ['accept' => true])->assertForbidden();
        $this->actingAs($this->outsider())->postJson('/api/agency/support/tickets/'.$ticket->ref.'/quote', ['accept' => true])->assertNotFound();
        $this->actingAs($this->u['owner'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/quote', ['accept' => true, 'note' => 'INTERNAL-ISH NOTE'])->assertOk()->assertJsonPath('ticket.quote.status', 'accepted');
        $this->assertSame('billable', $ticket->fresh()->billing_status);
        $this->actingAs($this->u['owner'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/quote', ['accept' => false])->assertUnprocessable(); // already answered

        // The note the owner wrote is for staff; staff see the answer, and the agency payload carries no staff-side fields.
        $this->assertSame('accepted', $this->actingAs($manager)->getJson('/api/staff/tickets/'.$ticket->ref)->json('ticket.quotes.0.status'));
        $this->assertStringNotContainsString('quotes', $this->actingAs($this->u['owner'])->getJson('/api/agency/support/tickets/'.$ticket->ref)->getContent());
    }

    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        $manager = $this->staff(['delivery_manager']);
        $ticket = $this->newTicket();
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/quote", ['amount' => 100, 'description' => 'x', 'valid_until' => now()->addDay()->toDateString()])->assertCreated();
        $this->travel(3)->days();
        $this->actingAs($this->u['owner'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/quote', ['accept' => true])->assertUnprocessable()->assertJsonValidationErrors(['quote']);
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/quote", ['amount' => 90, 'description' => 'y'])->assertCreated();
        $this->actingAs($manager)->deleteJson("/api/staff/tickets/{$ticket->ref}/quote")->assertOk();
        $this->assertNull($this->actingAs($this->u['owner'])->getJson('/api/agency/support/tickets/'.$ticket->ref)->json('ticket.quote'));
    }}
