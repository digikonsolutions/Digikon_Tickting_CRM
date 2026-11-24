<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\HoursRequest;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

class HoursRequestTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scheduling.require_timeline_review' => false]);
        $this->buildFixture();
        // The project has 30 h; the ticket uses 10 of them.
        $this->ticket = $this->makeTicket($this->project, 'Build header', 10);
    }

    private function makeTicket($project, string $title, float $hours): Ticket
    {
        static $number = 500;

        return Ticket::create([
            'agency_id' => $project->agency_id, 'work_item_id' => $project->id, 'created_by' => $this->u['client']->id, 'number' => ++$number,
            'title' => $title, 'description' => 'x', 'estimated_hours' => $hours, 'priority' => 'normal', 'status' => 'in_progress', 'position' => $number,
        ]);
    }

    private function ask(float $hours = 4, string $reason = 'The design grew.')
    {
        return $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$this->ticket->id}/hours-requests", ['hours' => $hours, 'reason' => $reason]);
    }

    public function test_the_employee_asks_and_the_ticket_shows_the_open_request(): void
    {
        $this->ask(4, 'The design grew.')->assertCreated()
            ->assertJsonPath('hours_request.status', 'pending')->assertJsonPath('hours_request.hours', '4.00');

        $this->getJson('/api/work-items/mine/tickets')->assertJsonPath('tickets.0.hours_request.hours', '4.00')
            ->assertJsonPath('tickets.0.hours_request.reason', 'The design grew.');
        $this->actingAs($this->u['client'])->getJson("/api/tickets/key/{$this->ticket->fresh()->key}")->assertOk()
            ->assertJsonPath('ticket.hours_request.hours', '4.00');
    }

    public function test_validation_and_one_open_request_per_ticket(): void
    {
        $this->ask(0)->assertUnprocessable()->assertJsonValidationErrors('hours');
        $this->ask(500)->assertUnprocessable()->assertJsonValidationErrors('hours');
        $this->ask(2, '')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->ask()->assertCreated();
        $this->ask(1)->assertUnprocessable()->assertJsonValidationErrors('hours');
        $this->assertSame(1, HoursRequest::query()->count());
    }

    public function test_only_the_assigned_employee_can_ask_and_closed_work_is_refused(): void
    {
        $other = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($other)->postJson("/api/tickets/{$this->ticket->id}/hours-requests", ['hours' => 1, 'reason' => 'Sneaky'])->assertNotFound();
        $this->actingAs($this->u['client'])->postJson("/api/tickets/{$this->ticket->id}/hours-requests", ['hours' => 1, 'reason' => 'x y z'])->assertForbidden();

        foreach (['done', 'cancelled'] as $status) {
            $this->ticket->update(['status' => $status]);
            $this->ask()->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->assertSame(0, HoursRequest::query()->count());
    }

    public function test_the_agency_hears_about_it_and_sees_it_in_its_list(): void
    {
        $this->ask(4)->assertCreated();

        $titles = DatabaseNotification::query()->where('notifiable_id', $this->u['owner']->id)->get()->pluck('data.title')->all();
        $this->assertContains("More hours requested on {$this->ticket->fresh()->key}", $titles);
        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $this->u['client']->id)->count(), 'the ticket creator too');
        $this->assertSame(0, DatabaseNotification::query()->where('notifiable_id', $this->u['other']->id)->count());

        $this->actingAs($this->u['owner'])->getJson('/api/agency/hours-requests')->assertOk()
            ->assertJsonCount(1, 'hours_requests')
            ->assertJsonPath('hours_requests.0.ticket.title', 'Build header')
            ->assertJsonPath('hours_requests.0.requested_by.id', $this->u['employee']->id);
    }

    public function test_approving_adds_the_hours_replans_and_tells_the_employee(): void
    {
        $id = $this->ask(4)->json('hours_request.id');

        $this->actingAs($this->u['client'])->postJson("/api/agency/hours-requests/$id/approve")->assertOk()->assertJsonPath('hours_request.status', 'approved');

        $this->assertSame('14.00', $this->ticket->fresh()->estimated_hours);
        $this->assertSame(14.0, (float) $this->ticket->allocations()->sum('hours'));
        $event = TicketEvent::query()->where('ticket_id', $this->ticket->id)->where('type', 'updated')->latest('id')->first();
        $this->assertSame(['from' => '10.00', 'to' => '14.00'], $event->payload['changes']['estimated_hours']);
        $this->assertSame($this->u['client']->id, HoursRequest::query()->find($id)->decided_by);
        $this->assertStringContainsString('was approved', DatabaseNotification::query()->where('notifiable_id', $this->u['employee']->id)->latest()->first()->data['body']);
        $this->getJson('/api/agency/hours-requests')->assertJsonCount(0, 'hours_requests');
        $this->actingAs($this->u['employee'])->getJson('/api/work-items/mine/tickets')->assertJsonPath('tickets.0.hours_request', null);
        $this->actingAs($this->u['client'])->postJson("/api/agency/hours-requests/$id/approve")->assertUnprocessable();
    }

    public function test_an_approval_that_would_exceed_the_project_hours_changes_nothing(): void
    {
        $this->makeTicket($this->project, 'Second', 18);
        $id = $this->ask(4)->json('hours_request.id'); // 10 + 18 + 4 = 32 > 30

        $this->actingAs($this->u['owner'])->postJson("/api/agency/hours-requests/$id/approve")->assertUnprocessable()->assertJsonValidationErrors('estimated_hours');
        $this->assertSame('10.00', $this->ticket->fresh()->estimated_hours);
        $this->assertSame('pending', HoursRequest::query()->find($id)->status);
    }

    public function test_declining_keeps_the_hours_and_passes_on_the_note(): void
    {
        $id = $this->ask(4)->json('hours_request.id');

        $this->actingAs($this->u['owner'])->postJson("/api/agency/hours-requests/$id/decline", ['note' => 'Please finish within the estimate.'])->assertOk()->assertJsonPath('hours_request.status', 'declined');

        $this->assertSame('10.00', $this->ticket->fresh()->estimated_hours);
        $body = DatabaseNotification::query()->where('notifiable_id', $this->u['employee']->id)->latest()->first()->data['body'];
        $this->assertStringContainsString('was declined', $body);
        $this->assertStringContainsString('Please finish within the estimate.', $body);
        // The employee may ask again afterwards.
        $this->ask(2)->assertCreated();
    }

    public function test_the_employee_can_withdraw_only_their_own_open_request(): void
    {
        $id = $this->ask()->json('hours_request.id');
        $other = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($other)->patchJson("/api/hours-requests/$id/cancel")->assertNotFound();
        $this->actingAs($this->u['employee'])->patchJson("/api/hours-requests/$id/cancel")->assertOk()->assertJsonPath('hours_request.status', 'cancelled');
        $this->patchJson("/api/hours-requests/$id/cancel")->assertUnprocessable();
        $this->actingAs($this->u['owner'])->postJson("/api/agency/hours-requests/$id/approve")->assertUnprocessable();
    }

    public function test_other_agencies_and_roles_cannot_answer(): void
    {
        $id = $this->ask()->json('hours_request.id');
        $rival = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => Agency::create(['name' => 'Rival'])->id]);

        $this->actingAs($rival)->postJson("/api/agency/hours-requests/$id/approve")->assertNotFound();
        $this->postJson("/api/agency/hours-requests/$id/decline")->assertNotFound();
        $this->getJson('/api/agency/hours-requests')->assertJsonCount(0, 'hours_requests');
        $this->actingAs($this->u['employee'])->postJson("/api/agency/hours-requests/$id/approve")->assertForbidden();
        $this->actingAs($this->u['admin'])->postJson("/api/agency/hours-requests/$id/approve")->assertForbidden();
        $this->assertSame('pending', HoursRequest::query()->find($id)->status);
    }
}
