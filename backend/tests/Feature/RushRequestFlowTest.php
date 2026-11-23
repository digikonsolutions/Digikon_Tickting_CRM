<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AgencyCharge;
use App\Models\EmployeeAssignment;
use App\Models\QueueEvent;
use App\Models\RushRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\EmployeeQueuePlanner;
use App\Services\RushRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

class RushRequestFlowTest extends TestCase
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
        $this->owner = $this->makeOwner($agency);
        $this->client = $this->makeClient($agency);
        $this->employee = $this->makeEmployee('Emma');
        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->assignment = $this->hold($this->employee, $agency, $this->owner);
    }

    private function day(int $offset): string
    {
        return $this->mon->addDays($offset)->toDateString();
    }

    private function item(string $title, float $hours, int $dueOffset = 20, string $status = 'scheduled'): WorkItem
    {
        $planner = app(EmployeeQueuePlanner::class);
        $item = WorkItem::create([
            'agency_id' => $this->assignment->agency_id, 'client_id' => $this->owner->id, 'assignment_id' => $this->assignment->id,
            'employee_id' => $this->employee->id, 'title' => $title, 'description' => 'D.', 'estimated_hours' => $hours,
            'start_date' => $this->day(0), 'due_date' => $this->day($dueOffset), 'status' => $status,
            'queue_position' => $planner->nextPosition($this->employee),
        ]);
        $planner->replan($this->employee);

        return $item;
    }

    /** @return array<string, mixed> */
    private function insertPayload(array $overrides = []): array
    {
        return [
            'type' => 'priority_insert', 'assignment_id' => $this->assignment->id, 'title' => 'Urgent fix',
            'description' => 'Checkout is broken.', 'estimated_hours' => 6, 'needed_by' => $this->day(2), 'reason' => 'Launch day.',
            ...$overrides,
        ];
    }

    private function submitAndQuote(array $payload, int $amount = 25000, ?User $submitter = null, array $quote = []): int
    {
        $id = $this->actingAs($submitter ?? $this->client)->postJson('/api/agency/rush-requests', $payload)
            ->assertCreated()->assertJsonPath('rush_request.status', 'pending')->json('rush_request.id');
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$id/quote", ['amount_minor' => $amount, ...$quote])
            ->assertOk()->assertJsonPath('rush_request.status', 'quoted');

        return $id;
    }

    private function inProgress(WorkItem $item): void
    {
        $item->update(['status' => 'in_progress']);
        Ticket::create([
            'agency_id' => $item->agency_id, 'work_item_id' => $item->id, 'created_by' => $this->owner->id, 'number' => 1,
            'title' => 'Started', 'description' => 'x', 'estimated_hours' => 6, 'priority' => 'normal', 'status' => 'in_progress', 'position' => 1,
        ]);
    }

    public function test_priority_insert_end_to_end_creates_project_charge_and_queue_events(): void
    {
        $a = $this->item('First', 12);
        $b = $this->item('Second', 6);
        $id = $this->submitAndQuote($this->insertPayload());

        $this->actingAs($this->owner)->getJson("/api/agency/rush-requests/$id")->assertOk()
            ->assertJsonPath('rush_request.can_accept', true)
            ->assertJsonPath('rush_request.quote.amount_minor', 25000)
            ->assertJsonPath('rush_request.quote.amount_display', '250.00 USD')
            ->assertJsonPath('rush_request.impact.requires_confirmation', false)
            ->assertJsonPath('rush_request.impact.new_project.planned_start', $this->day(0));
        $this->assertSame(0, WorkItem::query()->where('title', 'Urgent fix')->count(), 'viewing the quote creates nothing');

        $this->postJson("/api/agency/rush-requests/$id/accept")->assertOk()
            ->assertJsonPath('rush_request.status', 'fulfilled');

        $rush = WorkItem::query()->where('title', 'Urgent fix')->firstOrFail();
        $this->assertSame(1, $rush->queue_position);
        $this->assertSame(2, $a->fresh()->queue_position);
        $this->assertSame(3, $b->fresh()->queue_position);
        $this->assertSame([$this->day(0)], $rush->allocations()->pluck('work_date')->map->toDateString()->all());
        $this->assertSame($this->day(1), $a->allocations()->orderBy('work_date')->first()->work_date->toDateString());

        $charge = AgencyCharge::query()->sole();
        $this->assertSame(25000, $charge->amount_minor);
        $this->assertSame('USD', $charge->currency);
        $this->assertSame('unbilled', $charge->status);
        $this->assertSame($id, $charge->rush_request_id);
        $this->assertSame(1, QueueEvent::query()->where('type', 'rush_inserted')->where('work_item_id', $rush->id)->count());
        $this->assertSame($rush->id, RushRequest::query()->find($id)->fulfilled_work_item_id);
    }

    public function test_the_rush_project_goes_behind_work_already_in_progress(): void
    {
        $started = $this->item('Started', 12, 20, 'in_progress');
        $this->inProgress($started);
        $this->item('Waiting', 6);
        $id = $this->submitAndQuote($this->insertPayload(['needed_by' => $this->day(5)]));

        $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$id/accept")->assertOk();

        $rush = WorkItem::query()->where('title', 'Urgent fix')->firstOrFail();
        $this->assertSame(2, $rush->queue_position, 'right behind the project in progress');
        $this->assertSame(1, $started->fresh()->queue_position);
        $this->assertSame('6.00', $started->allocations()->whereDate('work_date', $this->day(0))->value('hours'), "today's hours on started work stay");
        $this->assertGreaterThan($this->day(0), $rush->allocations()->orderBy('work_date')->first()->work_date->toDateString());
    }

    public function test_clients_can_request_but_never_accept_or_see_prices(): void
    {
        $this->item('First', 6);
        $id = $this->submitAndQuote($this->insertPayload());

        $this->actingAs($this->client)->postJson("/api/agency/rush-requests/$id/accept")->assertForbidden();
        $this->postJson("/api/agency/rush-requests/$id/decline")->assertForbidden();
        $this->getJson("/api/agency/rush-requests/$id")->assertOk()
            ->assertJsonPath('rush_request.quote', null)->assertJsonPath('rush_request.can_accept', false)->assertJsonPath('rush_request.impact', null);
        $this->assertStringNotContainsString('25000', $this->getJson('/api/agency/rush-requests')->getContent());

        $otherClient = $this->makeClient($this->owner->agency);
        $this->actingAs($otherClient)->getJson("/api/agency/rush-requests/$id")->assertNotFound();
        $this->getJson('/api/agency/rush-requests')->assertJsonCount(0, 'rush_requests');
        $this->actingAs($this->owner)->getJson('/api/agency/rush-requests')->assertJsonCount(1, 'rush_requests');
        $this->assertSame(0, AgencyCharge::query()->count());
    }

    public function test_pushing_other_projects_past_their_due_dates_needs_an_explicit_confirm(): void
    {
        $tight = $this->item('Tight', 12, 1); // Mon + Tue, due Tuesday
        $id = $this->submitAndQuote($this->insertPayload(['needed_by' => $this->day(3)]));

        $this->actingAs($this->owner)->getJson("/api/agency/rush-requests/$id")
            ->assertJsonPath('rush_request.impact.requires_confirmation', true)
            ->assertJsonPath('rush_request.impact.affected.0.work_item_id', $tight->id)
            ->assertJsonPath('rush_request.impact.affected.0.slips_by_days', 1);

        $this->postJson("/api/agency/rush-requests/$id/accept")->assertUnprocessable()
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('impact.affected.0.title', 'Tight');
        $this->assertSame(0, AgencyCharge::query()->count());
        $this->assertSame(0, WorkItem::query()->where('title', 'Urgent fix')->count());
        $this->assertSame('quoted', RushRequest::query()->find($id)->status);
        $this->assertSame(1, $tight->fresh()->queue_position);

        $this->postJson("/api/agency/rush-requests/$id/accept", ['confirm_impact' => true])->assertOk();
        $this->assertSame(1, AgencyCharge::query()->count());
        $this->assertSame(2, $tight->fresh()->queue_position);
        $this->assertSame($this->day(2), $tight->allocations()->orderByDesc('work_date')->first()->work_date->toDateString(), 'the pushed project now ends after its due date');
    }

    public function test_work_that_cannot_fit_before_needed_by_cannot_be_accepted(): void
    {
        $id = $this->submitAndQuote($this->insertPayload(['estimated_hours' => 30, 'needed_by' => $this->day(1)]));

        $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$id/accept", ['confirm_impact' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('needed_by');
        $this->assertSame(0, AgencyCharge::query()->count());
        $this->assertSame(0, WorkItem::query()->count());
    }

    public function test_the_180_hour_cap_applies_to_requests(): void
    {
        $this->actingAs($this->owner)->postJson('/api/agency/rush-requests', $this->insertPayload(['estimated_hours' => 181]))
            ->assertUnprocessable()->assertJsonValidationErrors('estimated_hours');
    }

    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        $id = $this->submitAndQuote($this->insertPayload(), quote: ['valid_hours' => 1]);

        $this->travel(2)->hours();
        $this->assertSame(1, app(RushRequestService::class)->expireQuotes());
        $this->assertSame('expired', RushRequest::query()->find($id)->status);

        $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$id/accept")->assertUnprocessable();
        $this->assertSame(0, AgencyCharge::query()->count());
    }

    public function test_accepting_after_expiry_without_the_sweep_marks_it_expired(): void
    {
        $id = $this->submitAndQuote($this->insertPayload(), quote: ['valid_hours' => 1]);
        $this->travel(2)->hours();

        $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$id/accept")->assertUnprocessable();
        $this->assertSame('expired', RushRequest::query()->find($id)->status);
    }

    public function test_owner_can_decline_and_a_request_cannot_be_accepted_twice(): void
    {
        $this->item('First', 6);
        $declined = $this->submitAndQuote($this->insertPayload());
        $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$declined/decline")->assertOk()->assertJsonPath('rush_request.status', 'cancelled');
        $this->postJson("/api/agency/rush-requests/$declined/accept")->assertUnprocessable();
        $this->assertSame(0, AgencyCharge::query()->count());

        $id = $this->submitAndQuote($this->insertPayload(['title' => 'Second rush']));
        $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$id/accept")->assertOk();
        $this->postJson("/api/agency/rush-requests/$id/accept")->assertUnprocessable();
        $this->assertSame(1, AgencyCharge::query()->count());
    }

    public function test_requests_can_be_cancelled_by_the_requester_or_the_owner_only_while_open(): void
    {
        $id = $this->actingAs($this->client)->postJson('/api/agency/rush-requests', $this->insertPayload())->json('rush_request.id');
        $stranger = $this->makeClient($this->owner->agency);
        $this->actingAs($stranger)->patchJson("/api/agency/rush-requests/$id/cancel")->assertNotFound();
        $this->actingAs($this->client)->patchJson("/api/agency/rush-requests/$id/cancel")->assertOk()->assertJsonPath('rush_request.status', 'cancelled');
        $this->patchJson("/api/agency/rush-requests/$id/cancel")->assertUnprocessable();
    }

    public function test_admin_rejection_is_visible_to_the_agency_and_filters_work(): void
    {
        $id = $this->actingAs($this->client)->postJson('/api/agency/rush-requests', $this->insertPayload())->json('rush_request.id');
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$id/reject", ['reason' => 'We are fully booked on that date.'])
            ->assertOk()->assertJsonPath('rush_request.status', 'rejected');

        $this->actingAs($this->client)->getJson("/api/agency/rush-requests/$id")
            ->assertJsonPath('rush_request.status', 'rejected')->assertJsonPath('rush_request.message', 'We are fully booked on that date.');

        $this->actingAs($this->admin)->getJson('/api/admin/rush-requests?status=rejected')->assertJsonCount(1, 'rush_requests')
            ->assertJsonPath('rush_requests.0.agency.name', 'Acme');
        $this->getJson('/api/admin/rush-requests?status=open')->assertJsonCount(0, 'rush_requests');
        $this->getJson('/api/admin/rush-requests?agency_id=999999')->assertJsonCount(0, 'rush_requests');
        $this->actingAs($this->owner)->getJson('/api/admin/rush-requests')->assertForbidden();
        $this->postJson("/api/admin/rush-requests/$id/quote", ['amount_minor' => 1])->assertForbidden();
    }

    public function test_extra_employee_end_to_end_never_reveals_the_pool_employee(): void
    {
        $pool = $this->makeEmployee('Pool Person', ['agency_id' => null]);
        $payload = [
            'type' => 'extra_employee', 'title' => 'Landing page sprint', 'description' => 'Build three pages.',
            'skills_notes' => 'React and design', 'estimated_hours' => 12, 'needed_by' => $this->day(4), 'reason' => 'Deadline.',
        ];
        $id = $this->submitAndQuote($payload, 40000, quote: ['employee_id' => $pool->id]);

        $agencyView = $this->actingAs($this->owner)->getJson("/api/agency/rush-requests/$id")->assertOk()
            ->assertJsonPath('rush_request.status', 'quoted')->assertJsonPath('rush_request.employee', null);
        $this->assertStringNotContainsString('Pool Person', $agencyView->getContent());
        $this->assertStringNotContainsString((string) $pool->email, $agencyView->getContent());
        $this->assertSame($pool->id, RushRequest::query()->find($id)->proposed_employee_id);
        $this->actingAs($this->admin)->getJson('/api/admin/rush-requests')->assertJsonPath('rush_requests.0.proposed_employee.name', 'Pool Person');

        $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$id/accept")->assertOk()->assertJsonPath('rush_request.status', 'fulfilled');

        $temp = EmployeeAssignment::query()->where('employee_id', $pool->id)->sole();
        $this->assertSame('active', $temp->status);
        $this->assertSame('rush', $temp->source);
        $this->assertSame($this->owner->id, $temp->holder_id);
        $this->assertSame($this->day(4), $temp->expires_at->toDateString());
        $project = WorkItem::query()->where('title', 'Landing page sprint')->sole();
        $this->assertSame($temp->id, $project->assignment_id);
        $this->assertSame(12.0, (float) $project->allocations()->sum('hours'));
        $this->assertSame(40000, AgencyCharge::query()->sole()->amount_minor);
        $this->assertSame($temp->id, RushRequest::query()->find($id)->temp_assignment_id);
    }

    public function test_extra_employee_rejection_shows_only_the_generic_message(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/agency/rush-requests', [
            'type' => 'extra_employee', 'title' => 'Help', 'description' => 'Need hands.', 'estimated_hours' => 6, 'needed_by' => $this->day(3),
        ])->assertCreated()->json('rush_request.id');
        $this->actingAs($this->admin)->postJson("/api/admin/rush-requests/$id/reject", ['reason' => 'Nobody with those skills free; Pool Person is on leave.'])->assertOk();

        $response = $this->actingAs($this->owner)->getJson("/api/agency/rush-requests/$id")->assertOk()
            ->assertJsonPath('rush_request.message', 'No team member is available for this request.');
        $this->assertStringNotContainsString('Pool Person', $response->getContent());
        $this->assertStringNotContainsString('on leave', $response->getContent());
    }

    public function test_extra_employee_quote_needs_an_available_employee_with_enough_free_hours(): void
    {
        $busy = $this->makeEmployee('Busy', ['agency_id' => null]);
        $held = $this->makeEmployee('Held', ['agency_id' => null]);
        $this->hold($held, $this->makeAgency('Elsewhere'), $this->makeOwner($this->makeAgency('Other owner agency')));
        $otherAgencyEmployee = $this->makeEmployee('Theirs', ['agency_id' => $this->makeAgency('Third')->id]);
        $payload = ['type' => 'extra_employee', 'title' => 'Help', 'description' => 'Need hands.', 'estimated_hours' => 30, 'needed_by' => $this->day(1)];
        $id = $this->actingAs($this->owner)->postJson('/api/agency/rush-requests', $payload)->json('rush_request.id');

        $this->actingAs($this->admin);
        // 30 hours cannot fit in two days even for a free employee.
        $this->postJson("/api/admin/rush-requests/$id/quote", ['amount_minor' => 100, 'employee_id' => $busy->id])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        // Already held elsewhere, belonging to another agency, or missing: all refused.
        foreach ([$held->id, $otherAgencyEmployee->id, 999999] as $employeeId) {
            $this->postJson("/api/admin/rush-requests/$id/quote", ['amount_minor' => 100, 'employee_id' => $employeeId])->assertUnprocessable();
        }
        $this->postJson("/api/admin/rush-requests/$id/quote", ['amount_minor' => 100])->assertUnprocessable();
    }

    public function test_extra_employee_taken_before_acceptance_fails_without_revealing_anything(): void
    {
        $pool = $this->makeEmployee('Pool Person', ['agency_id' => null]);
        $id = $this->submitAndQuote(
            ['type' => 'extra_employee', 'title' => 'Help', 'description' => 'Need hands.', 'estimated_hours' => 6, 'needed_by' => $this->day(3)],
            1000,
            $this->owner,
            ['employee_id' => $pool->id],
        );
        $this->hold($pool, $this->makeAgency('Quick'), $this->makeOwner($this->makeAgency('Quick owner')));

        $response = $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$id/accept")->assertUnprocessable();
        $this->assertStringNotContainsString('Pool Person', $response->getContent());
        $this->assertSame(0, AgencyCharge::query()->count());
        $this->assertSame('quoted', RushRequest::query()->find($id)->status);
    }

    public function test_rush_requests_stay_inside_the_agency(): void
    {
        $rival = $this->makeOwner($this->makeAgency('Rival'));
        $id = $this->submitAndQuote($this->insertPayload());

        $this->actingAs($rival)->getJson("/api/agency/rush-requests/$id")->assertNotFound();
        $this->postJson("/api/agency/rush-requests/$id/accept")->assertNotFound();
        $this->postJson("/api/agency/rush-requests/$id/decline")->assertNotFound();
        $this->patchJson("/api/agency/rush-requests/$id/cancel")->assertNotFound();
        $this->getJson('/api/agency/rush-requests')->assertJsonCount(0, 'rush_requests');
        // Asking for a team member another agency holds is refused.
        $this->postJson('/api/agency/rush-requests', $this->insertPayload())->assertUnprocessable()->assertJsonValidationErrors('assignment_id');
    }

    public function test_charges_list_filters_and_totals_in_minor_units(): void
    {
        $this->item('First', 6);
        $id = $this->submitAndQuote($this->insertPayload(), 12345);
        $this->actingAs($this->owner)->postJson("/api/agency/rush-requests/$id/accept")->assertOk();

        $this->actingAs($this->admin)->getJson('/api/admin/charges')->assertOk()
            ->assertJsonPath('charges.0.amount_minor', 12345)->assertJsonPath('charges.0.amount_display', '123.45 USD')
            ->assertJsonPath('totals.0.amount_minor', 12345)->assertJsonPath('totals.0.currency', 'USD');
        $this->getJson('/api/admin/charges?status=paid')->assertJsonCount(0, 'charges');
        $this->getJson('/api/admin/charges?agency_id='.$this->owner->agency_id.'&status=unbilled')->assertJsonCount(1, 'charges');
        $this->getJson('/api/admin/charges?from='.$this->day(1))->assertJsonCount(0, 'charges');
        $this->actingAs($this->owner)->getJson('/api/admin/charges')->assertForbidden();
    }

    public function test_creating_requests_is_throttled(): void
    {
        $this->actingAs($this->owner);
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/agency/rush-requests', $this->insertPayload())->assertCreated();
        }
        $this->postJson('/api/agency/rush-requests', $this->insertPayload())->assertStatus(429);
    }
}
