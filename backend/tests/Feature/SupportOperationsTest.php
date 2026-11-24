<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** Scope and billing control, time, feedback and canned replies. */
class SupportOperationsTest extends TestCase
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

    private function newTicket(): Ticket
    {
        $ref = $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets', ['type' => 'technical_support', 'title' => 'Help', 'description' => 'Please.', 'expected_result' => 'Fixed.', 'details' => ['goal' => 'x']])->assertCreated()->json('ticket.ref');

        return Ticket::query()->where('ref', $ref)->firstOrFail();
    }

    public function test_a_builder_only_proposes_scope_and_a_delivery_manager_decides(): void
    {
        $builder = $this->staff(['builder']);
        $manager = $this->staff(['delivery_manager']);
        $ticket = $this->newTicket();
        $this->actingAs($builder)->postJson("/api/staff/tickets/{$ticket->ref}/scope", ['scope_classification' => 'new_scope'])->assertOk();
        $fresh = $ticket->fresh();
        $this->assertNull($fresh->scope_classification);
        $this->assertSame('new_scope', $fresh->scope_proposal['scope_classification']);
        $this->assertSame('billable', $fresh->scope_proposal['billing_status']);

        $this->actingAs($builder)->postJson("/api/staff/tickets/{$ticket->ref}/scope/approve")->assertForbidden();
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/scope/approve")->assertOk()->assertJsonPath('ticket.scope_classification', 'new_scope');
        $approved = $ticket->fresh();
        $this->assertSame($manager->id, $approved->scope_approved_by);
        $this->assertNull($approved->scope_proposal);

        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/scope", ['scope_classification' => 'included_revision', 'billing_status' => 'complimentary'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/scope", ['scope_classification' => 'included_revision', 'billing_status' => 'complimentary', 'reason' => 'Goodwill'])->assertOk()->assertJsonPath('ticket.billing_status', 'complimentary');
        $this->actingAs($this->staff(['finance']))->postJson("/api/staff/tickets/{$ticket->ref}/scope", ['scope_classification' => 'bug'])->assertForbidden();
        $this->actingAs($this->u['client'])->postJson("/api/staff/tickets/{$ticket->ref}/scope", ['scope_classification' => 'bug'])->assertForbidden();
    }

    public function test_scope_billing_time_and_feedback_never_reach_the_agency(): void
    {
        $manager = $this->staff(['delivery_manager', 'builder']);
        $ticket = $this->newTicket();
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/scope", ['scope_classification' => 'new_scope', 'reason' => 'INTERNAL-BILLING-REASON'])->assertOk();
        $this->actingAs($manager)->postJson("/api/staff/tickets/{$ticket->ref}/time", ['minutes' => 45, 'billable' => true, 'note' => 'INTERNAL-TIME-NOTE'])->assertCreated();
        foreach (['/api/agency/support/tickets/'.$ticket->ref, '/api/agency/support/tickets', "/api/tickets/{$ticket->id}/timeline"] as $url) {
            $body = $this->actingAs($this->u['owner'])->getJson($url)->assertOk()->getContent();
            foreach (['INTERNAL-BILLING-REASON', 'INTERNAL-TIME-NOTE', 'billing_status', 'scope_classification', 'billable', 'actual_minutes', 'scope_proposal', '"feedback":', 'rating'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "$url leaks $leak");
            }
        }
    }

    public function test_time_is_logged_by_the_team_and_rolled_up(): void
    {
        $builder = $this->staff(['builder']);
        $ticket = $this->newTicket();
        $this->actingAs($builder)->postJson("/api/staff/tickets/{$ticket->ref}/time", ['minutes' => 0])->assertUnprocessable();
        $this->actingAs($builder)->postJson("/api/staff/tickets/{$ticket->ref}/time", ['minutes' => 30, 'billable' => true])->assertCreated();
        $time = $this->actingAs($builder)->postJson("/api/staff/tickets/{$ticket->ref}/time", ['minutes' => 15, 'activity' => 'qa'])->assertCreated()->json('time');
        $this->assertSame(45, $time['actual_minutes']);
        $this->assertSame(30, $time['billable_minutes']);
        $this->actingAs($this->staff(['builder']))->patchJson("/api/staff/tickets/{$ticket->ref}", ['estimate_hours' => 2])->assertOk();
        $this->assertEquals(2, $this->actingAs($builder)->getJson("/api/staff/tickets/{$ticket->ref}/time")->json('time.estimate_hours'));
        // The estimate on a support ticket is information only: nothing was scheduled.
        $this->assertNull($ticket->fresh()->work_item_id);
        $this->assertSame(0, DB::table('ticket_allocations')->where('ticket_id', $ticket->id)->count());
        $this->actingAs($this->u['client'])->postJson("/api/staff/tickets/{$ticket->ref}/time", ['minutes' => 5])->assertForbidden();
        $this->actingAs($this->staff([]))->postJson("/api/staff/tickets/{$ticket->ref}/time", ['minutes' => 5])->assertNotFound();
    }

    public function test_the_agency_leaves_one_satisfaction_answer_once_resolved(): void
    {
        $ticket = $this->newTicket();
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/feedback', ['rating' => 5])->assertUnprocessable();
        $ticket->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'resolution_summary' => 'Done', 'resolution_category' => 'fixed'])->save();
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/feedback', ['rating' => 6])->assertUnprocessable();
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/feedback', ['rating' => 4, 'comment' => 'Quick'])->assertOk();
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/feedback', ['rating' => 5])->assertOk();
        $this->assertSame(1, DB::table('ticket_feedback')->where('ticket_id', $ticket->id)->count());
        $this->actingAs($this->outsider())->postJson('/api/agency/support/tickets/'.$ticket->ref.'/feedback', ['rating' => 1])->assertNotFound();
        $staff = $this->actingAs($this->staff(['triage']))->getJson('/api/staff/tickets/'.$ticket->ref)->json('ticket.feedback');
        $this->assertSame(5, $staff['rating']);
        $manager = $this->staff(['delivery_manager']);
        $this->assertEquals(5, $this->actingAs($manager)->getJson('/api/staff/dashboard?from=2000-01-01&to=2099-12-31')->json('kpis.feedback_average'));
    }

    public function test_canned_responses_are_written_by_managers_and_used_by_staff(): void
    {
        $manager = $this->staff(['triage']);
        $builder = $this->staff(['builder']);
        $id = $this->actingAs($manager)->postJson('/api/staff/canned-responses', ['title' => 'Thanks', 'body' => 'Thanks, we are on it.'])->assertCreated()->json('response.id');
        $this->actingAs($builder)->postJson('/api/staff/canned-responses', ['title' => 'x', 'body' => 'y'])->assertForbidden();
        $this->assertCount(1, $this->actingAs($builder)->getJson('/api/staff/canned-responses')->json('responses'));
        $this->actingAs($manager)->patchJson("/api/staff/canned-responses/{$id}", ['title' => 'Thank you'])->assertOk();
        $this->actingAs($this->u['client'])->getJson('/api/staff/canned-responses')->assertForbidden();
        $this->actingAs($manager)->deleteJson("/api/staff/canned-responses/{$id}")->assertOk();
    }

    public function test_the_lists_do_not_grow_with_the_number_of_tickets(): void
    {
        $manager = $this->staff(['triage']);
        // Two tickets first, so every relation has something to load; then four more must cost no extra queries.
        $this->newTicket();
        $this->newTicket();
        $count = function () use ($manager): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->u['owner'])->getJson('/api/agency/support/tickets')->assertOk();
            $this->actingAs($manager)->getJson('/api/staff/tickets')->assertOk();

            return count(DB::getQueryLog());
        };
        $one = $count();
        foreach (range(1, 4) as $i) {
            $this->newTicket();
        }
        // One query may differ (a session or agency lookup); anything per ticket would add four or more.
        $this->assertLessThanOrEqual($one + 1, $count());
    }
}
