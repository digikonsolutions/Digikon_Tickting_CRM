<?php

namespace Tests\Feature;

use App\Models\TicketReview;
use App\Models\TicketSubmission;
use App\Notifications\TicketNeedsYourInput;
use App\Notifications\TicketStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** The employee's review package and the two reviews: our own check first, then the agency. */
class TicketReviewFlowTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scheduling.internal_review' => true]);
        $this->buildFixture();
    }

    private function package(array $extra = []): array
    {
        return ['summary' => 'Built the follow-up workflow.', 'links' => ['https://app.example.com/workflows/1'], 'test_steps' => 'Submit the form with the test contact.', ...$extra];
    }

    private function started()
    {
        $ticket = $this->makeTicket('owner', 'Flow', 2);
        $this->transition('employee', $ticket, 'start');

        return $ticket;
    }

    private function send($ticket, array $extra = [])
    {
        return $this->actingAs($this->u['employee'])->postJson('/api/tickets/'.$ticket->id.'/submissions', $this->package($extra));
    }

    private function review(string $by, $ticket, string $stage, string $decision, ?string $feedback = null)
    {
        return $this->actingAs($this->u[$by])->postJson('/api/tickets/'.$ticket->id.'/reviews', array_filter(['stage' => $stage, 'decision' => $decision, 'feedback' => $feedback]));
    }

    public function test_sending_a_package_moves_the_ticket_to_our_own_check_and_tells_admins(): void
    {
        Notification::fake();
        $ticket = $this->started();
        $this->send($ticket, ['limitations' => 'Only tested on desktop'])->assertCreated()->assertJsonPath('submission.version', 1)->assertJsonPath('ticket_status', 'in_internal_review');
        $this->assertSame('in_internal_review', $ticket->fresh()->status);
        Notification::assertSentTo($this->u['admin'], TicketNeedsYourInput::class);
        Notification::assertNotSentTo($this->u['owner'], TicketNeedsYourInput::class);
        $this->actingAs($this->u['admin'])->getJson('/api/tickets/'.$ticket->id.'/submissions')->assertOk()->assertJsonPath('submissions.0.limitations', 'Only tested on desktop');
        $this->actingAs($this->u['admin'])->getJson('/api/tickets/'.$ticket->id.'/timeline')->assertOk();
    }

    public function test_the_package_needs_a_summary_a_link_and_test_steps_and_only_the_assigned_employee_sends_it(): void
    {
        $ticket = $this->started();
        $this->send($ticket, ['links' => []])->assertStatus(422)->assertJsonValidationErrors('links');
        $this->send($ticket, ['links' => ['not a link']])->assertStatus(422);
        $this->send($ticket, ['summary' => 'short'])->assertStatus(422)->assertJsonValidationErrors('summary');
        $this->send($ticket, ['test_steps' => ''])->assertStatus(422)->assertJsonValidationErrors('test_steps');
        foreach (['owner', 'client', 'admin'] as $who) {
            $this->actingAs($this->u[$who])->postJson('/api/tickets/'.$ticket->id.'/submissions', $this->package())->assertForbidden();
        }
        $this->assertSame('in_progress', $ticket->fresh()->status);
        // A ticket that is not in progress cannot be sent.
        $todo = $this->makeTicket('owner', 'Later', 1);
        $this->send($todo)->assertStatus(422);
    }

    public function test_a_bare_send_to_review_is_refused_while_the_two_stage_review_is_on(): void
    {
        $ticket = $this->started();
        $this->actingAs($this->u['employee'])->postJson('/api/tickets/'.$ticket->id.'/transitions', ['action' => 'send_to_review'])->assertStatus(422);
        $this->assertSame('in_progress', $ticket->fresh()->status);
    }

    public function test_internal_approval_sends_it_on_and_the_agency_approval_finishes_it(): void
    {
        Notification::fake();
        $ticket = $this->started();
        $this->send($ticket)->assertCreated();
        // The agency cannot decide before our own check is done, nor take the internal stage.
        $this->review('owner', $ticket, 'agency', 'approved')->assertStatus(422);
        $this->review('owner', $ticket, 'internal', 'approved')->assertForbidden();
        $this->review('employee', $ticket, 'internal', 'approved')->assertForbidden();

        $this->review('admin', $ticket, 'internal', 'approved')->assertCreated()->assertJsonPath('ticket_status', 'in_review');
        Notification::assertSentTo($this->u['owner'], TicketNeedsYourInput::class);
        $this->review('owner', $ticket, 'agency', 'approved')->assertCreated()->assertJsonPath('ticket_status', 'done');
        $this->assertSame(['internal', 'agency'], TicketReview::orderBy('id')->pluck('stage')->all());
    }

    public function test_internal_changes_go_back_to_the_employee_and_the_agency_never_sees_that_round(): void
    {
        Notification::fake();
        $ticket = $this->started();
        $this->send($ticket)->assertCreated();
        $this->review('admin', $ticket, 'internal', 'changes_requested')->assertStatus(422)->assertJsonValidationErrors('feedback');
        $this->review('admin', $ticket, 'internal', 'changes_requested', 'The tag is missing on the second branch.')->assertCreated()->assertJsonPath('ticket_status', 'in_progress');
        Notification::assertSentTo($this->u['employee'], TicketStatusChanged::class);
        Notification::assertNotSentTo($this->u['owner'], TicketStatusChanged::class);

        // Nothing of this round reaches the agency.
        $this->actingAs($this->u['owner'])->getJson('/api/tickets/'.$ticket->id.'/submissions')->assertOk()->assertJsonCount(0, 'submissions');
        $this->assertStringNotContainsString('tag is missing', json_encode($this->actingAs($this->u['owner'])->getJson('/api/tickets/'.$ticket->id.'/comments')->json()));

        // The employee fixes it and sends version 2; the thread keeps both.
        $this->send($ticket, ['summary' => 'Added the missing tag.'])->assertCreated()->assertJsonPath('submission.version', 2);
        $thread = $this->actingAs($this->u['employee'])->getJson('/api/tickets/'.$ticket->id.'/submissions')->json('submissions');
        $this->assertSame([1, 2], array_column($thread, 'version'));
        $this->assertSame('The tag is missing on the second branch.', $thread[0]['reviews'][0]['feedback']);
    }

    public function test_the_agency_sees_the_released_package_but_not_internal_feedback_and_not_our_status(): void
    {
        $ticket = $this->started();
        $this->send($ticket)->assertCreated();
        $row = collect($this->actingAs($this->u['owner'])->getJson('/api/agency/tickets')->json('tickets'))->firstWhere('id', $ticket->id);
        $this->assertSame('in_progress', $row['status'], 'the agency does not see the internal check');

        $this->review('admin', $ticket, 'internal', 'approved', 'Looks right to me.')->assertCreated();
        $thread = $this->actingAs($this->u['owner'])->getJson('/api/tickets/'.$ticket->id.'/submissions')->assertOk()->json('submissions');
        $this->assertCount(1, $thread);
        $this->assertSame([], $thread[0]['reviews'], 'internal feedback stays internal');
        $this->assertSame('Built the follow-up workflow.', $thread[0]['summary']);
        // Another agency sees nothing.
        $this->actingAs($this->outsider())->getJson('/api/tickets/'.$ticket->id.'/submissions')->assertNotFound();
    }

    public function test_the_agency_can_ask_for_changes_and_the_next_package_is_version_two(): void
    {
        $ticket = $this->started();
        $this->send($ticket)->assertCreated();
        $this->review('admin', $ticket, 'internal', 'approved')->assertCreated();
        $this->review('client', $ticket, 'agency', 'changes_requested', 'Please also add a thank-you page.')->assertCreated()->assertJsonPath('ticket_status', 'changes_requested');
        $this->transition('employee', $ticket, 'restart_work');
        $this->send($ticket)->assertCreated()->assertJsonPath('submission.version', 2);
        $this->assertSame(2, TicketSubmission::where('ticket_id', $ticket->id)->count());
        $this->assertSame('in_internal_review', $ticket->fresh()->status);
    }

    public function test_with_the_internal_check_off_the_package_goes_straight_to_the_agency(): void
    {
        config(['scheduling.internal_review' => false]);
        $ticket = $this->started();
        $this->send($ticket)->assertCreated()->assertJsonPath('ticket_status', 'in_review');
        $this->assertTrue(TicketSubmission::first()->visible_to_agency);
    }
}
