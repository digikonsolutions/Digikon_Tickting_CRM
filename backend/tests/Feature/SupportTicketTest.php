<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EndClient;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** The support module, milestone 1: submitting, the lifecycle, who sees what, and that nothing leaks across agencies. */
class SupportTicketTest extends TestCase
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

    private function payload(array $extra = []): array
    {
        return ['type' => 'technical_support', 'title' => 'Forms are not sending', 'description' => 'The contact form does nothing.', 'expected_result' => 'A confirmation email.', 'details' => ['goal' => 'Receive leads'], ...$extra];
    }

    private function submit(string $by = 'client', array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->u[$by])->postJson('/api/agency/support/tickets', $this->payload($extra));
    }

    private function newTicket(array $extra = []): Ticket
    {
        return Ticket::query()->where('ref', $this->submit('client', $extra)->assertCreated()->json('ticket.ref'))->firstOrFail();
    }

    public function test_an_agency_user_submits_and_the_ticket_is_routed_without_touching_the_schedule(): void
    {
        $allocations = DB::table('ticket_allocations')->count();
        $response = $this->submit()->assertCreated();
        $this->assertSame('DG-1001', $response->json('ticket.ref'));
        $this->assertSame('new', $response->json('ticket.status'));
        $this->assertSame('support', $response->json('ticket.kind'));
        $ticket = Ticket::query()->where('ref', 'DG-1001')->firstOrFail();
        $this->assertNull($ticket->work_item_id);
        $this->assertNull($ticket->estimated_hours);
        $this->assertSame($this->agency->id, $ticket->agency_id);
        $this->assertSame($this->u['client']->id, $ticket->submitted_by_id);
        $this->assertSame('support', $ticket->assigned_team);
        $this->assertSame($allocations, DB::table('ticket_allocations')->count());
        $this->assertSame('DG-1002', $this->submit()->assertCreated()->json('ticket.ref'));
    }

    public function test_legacy_tickets_get_a_ref_and_show_in_the_new_list_with_a_mapped_status(): void
    {
        $legacy = $this->makeTicket();
        $this->assertNotNull($legacy->fresh()->ref);
        $row = collect($this->actingAs($this->u['owner'])->getJson('/api/agency/support/tickets')->assertOk()->json('tickets'))->firstWhere('id', $legacy->id);
        $this->assertSame('assigned', $row['status']);
        $this->assertTrue($row['is_legacy']);
    }

    public function test_validation_names_the_field_and_server_owned_fields_are_prohibited(): void
    {
        $this->submit('client', ['type' => 'bug', 'details' => []])->assertUnprocessable()->assertJsonValidationErrors(['details.steps', 'details.actual_result', 'details.evidence']);
        $this->submit('client', ['type' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['type']);
        foreach (['kind' => 'support', 'status' => 'closed', 'assigned_to_id' => 1, 'ref' => 'DG-1', 'scope_classification' => 'bug'] as $field => $value) {
            $this->submit('client', [$field => $value])->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
        $other = EndClient::create(['agency_id' => $this->outsider()->agency_id, 'name' => 'Not mine']);
        $this->submit('client', ['end_client_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors(['end_client_id']);
        $this->submit('client', ['priority' => 'urgent'])->assertUnprocessable()->assertJsonValidationErrors(['priority']);
        $this->submit('client', ['priority' => 'urgent', 'confirm_urgent' => true])->assertCreated();
        $this->actingAs($this->u['employee'])->postJson('/api/agency/support/tickets', $this->payload())->assertForbidden();
    }

    public function test_a_critical_incident_is_forced_to_p1_and_priority_is_suggested_from_the_impact(): void
    {
        $r = $this->submit('client', ['type' => 'critical_incident', 'details' => ['what_down' => 'Booking', 'since' => 'Today', 'revenue_impact' => 'High', 'emergency_phone' => '+92 300 0000000']])->assertCreated();
        $this->assertSame('urgent', $r->json('ticket.priority'));
        $this->assertSame('P1 Critical', $r->json('ticket.priority_label'));
        $hint = $this->actingAs($this->u['client'])->postJson('/api/agency/support/suggest-priority', ['type' => 'technical_support', 'impact' => ['live_broken' => true, 'workaround' => false]])->assertOk();
        $this->assertSame('high', $hint->json('priority'));
    }

    public function test_agencies_cannot_see_each_others_tickets(): void
    {
        $ticket = $this->newTicket();
        $outsider = $this->outsider();
        $this->actingAs($outsider)->getJson('/api/agency/support/tickets/'.$ticket->ref)->assertNotFound();
        $this->assertSame([], $this->actingAs($outsider)->getJson('/api/agency/support/tickets')->assertOk()->json('tickets'));
        $this->actingAs($outsider)->postJson('/api/agency/support/tickets/'.$ticket->ref.'/reopen', ['reason' => 'x'])->assertNotFound();
        $this->actingAs($outsider)->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'hi'])->assertForbidden();
        $this->actingAs($outsider)->postJson("/api/tickets/{$ticket->id}/links", ['url' => 'https://example.com'])->assertNotFound();
    }

    public function test_agency_json_never_contains_internal_data(): void
    {
        $triage = $this->staff(['triage'], 'Tara Triage');
        $ticket = $this->newTicket(['description' => 'The contact form does nothing.']);
        $this->actingAs($triage)->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'INTERNAL-SECRET-NOTE', 'is_internal' => true])->assertCreated();
        $this->actingAs($triage)->postJson("/api/tickets/{$ticket->id}/links", ['url' => 'https://internal.example/loom', 'title' => 'INTERNAL-LINK', 'is_internal' => true])->assertCreated();
        $this->actingAs($triage)->postJson("/api/staff/tickets/{$ticket->ref}/assign", ['assigned_to_id' => $triage->id])->assertOk();
        $this->actingAs($triage)->patchJson("/api/staff/tickets/{$ticket->ref}", ['priority' => 'high', 'priority_reason' => 'INTERNAL-REASON'])->assertOk();

        foreach (['/api/agency/support/tickets/'.$ticket->ref, '/api/agency/support/tickets', "/api/tickets/{$ticket->id}/comments", "/api/tickets/{$ticket->id}/attachments", "/api/tickets/{$ticket->id}/timeline"] as $url) {
            $body = $this->actingAs($this->u['owner'])->getJson($url)->assertOk()->getContent();
            foreach (['INTERNAL-SECRET-NOTE', 'INTERNAL-LINK', 'INTERNAL-REASON', 'assigned_to', 'secret_flagged', 'scope_classification', 'billing_status', 'allowed_transitions'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "$url leaks $leak");
            }
        }
        // Searching for an internal note finds nothing for the agency and the ticket for staff.
        $this->assertSame([], $this->actingAs($this->u['owner'])->getJson('/api/agency/support/tickets?q=INTERNAL-SECRET')->json('tickets'));
        $this->assertCount(1, $this->actingAs($triage)->getJson('/api/staff/tickets?q=INTERNAL-SECRET')->json('tickets'));
        $staffView = $this->actingAs($triage)->getJson("/api/tickets/{$ticket->id}/comments")->assertOk()->getContent();
        $this->assertStringContainsString('INTERNAL-SECRET-NOTE', $staffView);
    }

    public function test_the_status_follows_the_state_machine_and_each_step_needs_its_data(): void
    {
        $triage = $this->staff(['triage'], 'Tara');
        $builder = $this->staff(['builder'], 'Ben');
        $ticket = $this->newTicket();
        $go = fn (User $who, string $to, array $data = []) => $this->actingAs($who)->postJson("/api/staff/tickets/{$ticket->ref}/transition", ['to' => $to, ...$data]);

        $go($triage, 'resolved')->assertUnprocessable(); // new cannot jump
        $go($triage, 'triage')->assertOk()->assertJsonPath('ticket.status', 'triage');
        $go($triage, 'assigned')->assertUnprocessable()->assertJsonValidationErrors(['assigned_to_id']);
        $go($triage, 'assigned', ['assigned_to_id' => $builder->id])->assertOk()->assertJsonPath('ticket.status', 'assigned');
        $go($builder, 'in_progress')->assertOk();
        $go($builder, 'waiting_agency', ['waiting_reason' => 'Need the form URL'])->assertUnprocessable()->assertJsonValidationErrors(['follow_up_date']);
        $go($builder, 'waiting_agency', ['waiting_reason' => 'Need the form URL', 'follow_up_date' => now()->addDays(2)->toDateString()])->assertOk();
        $this->assertSame('Need the form URL', $this->actingAs($this->u['client'])->getJson('/api/agency/support/tickets/'.$ticket->ref)->json('ticket.waiting.reason'));
        $go($builder, 'in_progress')->assertOk();
        $go($builder, 'resolved', ['resolution_summary' => 'Fixed'])->assertUnprocessable()->assertJsonValidationErrors(['resolution_category']);
        $go($builder, 'resolved', ['resolution_summary' => 'Fixed', 'resolution_category' => 'fixed'])->assertOk()->assertJsonPath('ticket.status', 'resolved');
        // A person without the capability cannot even see the ticket's controls.
        $this->actingAs($this->u['employee'])->postJson("/api/staff/tickets/{$ticket->ref}/transition", ['to' => 'closed'])->assertNotFound();
        $go($builder, 'closed')->assertForbidden();
        $go($triage, 'closed')->assertOk()->assertJsonPath('ticket.status', 'closed');
        $this->assertNotNull($ticket->fresh()->closed_at);
    }

    public function test_a_bug_needs_qa_and_the_reviewer_must_differ_from_the_builder_on_critical(): void
    {
        $manager = $this->staff(['delivery_manager', 'triage'], 'Mia');
        $builder = $this->staff(['builder', 'qa'], 'Ben');
        $qa = $this->staff(['qa'], 'Quinn');
        $ticket = $this->newTicket(['type' => 'bug', 'priority' => 'high', 'details' => ['steps' => '1. Click', 'actual_result' => 'Error', 'affected' => 'All', 'when' => 'Today', 'evidence' => 'https://loom.example/1']]);
        $go = fn (User $who, string $to, array $data = []) => $this->actingAs($who)->postJson("/api/staff/tickets/{$ticket->ref}/transition", ['to' => $to, ...$data]);
        $go($manager, 'triage')->assertOk();
        $go($manager, 'assigned', ['assigned_to_id' => $builder->id])->assertOk();
        $go($builder, 'in_progress')->assertOk();
        $go($builder, 'resolved', ['resolution_summary' => 'x', 'resolution_category' => 'fixed'])->assertUnprocessable();
        $ticket->forceFill(['priority' => 'urgent'])->save();
        $go($builder, 'qa', ['reviewer_id' => $builder->id])->assertUnprocessable()->assertJsonValidationErrors(['reviewer_id']);
        $go($builder, 'qa', ['reviewer_id' => $this->u['employee']->id])->assertUnprocessable(); // no QA capability
        $go($builder, 'qa', ['reviewer_id' => $qa->id])->assertOk()->assertJsonPath('ticket.status', 'qa');
        $go($qa, 'in_progress')->assertUnprocessable()->assertJsonValidationErrors(['failure_note']);
        $go($qa, 'in_progress', ['failure_note' => 'Still fails on mobile'])->assertOk();
        $go($builder, 'qa', ['reviewer_id' => $qa->id])->assertOk();
        $go($qa, 'resolved', ['resolution_summary' => 'Fixed and retested', 'resolution_category' => 'fixed'])->assertOk();
        // The failure note is internal: the agency timeline never shows it.
        $this->assertStringNotContainsString('Still fails on mobile', $this->actingAs($this->u['owner'])->getJson("/api/tickets/{$ticket->id}/timeline")->getContent());

        // An admin may override the builder != reviewer rule, and has to say why.
        $ticket->forceFill(['status' => 'in_progress', 'assigned_to_id' => $builder->id])->save();
        $admin = $this->u['admin'];
        $go($admin, 'qa', ['reviewer_id' => $builder->id])->assertUnprocessable();
        $go($admin, 'qa', ['reviewer_id' => $builder->id, 'override_reason' => 'Only QA online'])->assertOk();
    }

    public function test_agency_replies_resume_waiting_tickets_and_the_first_staff_reply_is_stamped(): void
    {
        $triage = $this->staff(['triage']);
        $ticket = $this->newTicket();
        $this->assertNull($ticket->first_response_at);
        $this->actingAs($triage)->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Looking into it'])->assertCreated();
        $this->assertNotNull($ticket->fresh()->first_response_at);

        $ticket->forceFill(['status' => 'waiting_agency', 'waiting_kind' => 'agency', 'waiting_reason' => 'Need info', 'follow_up_date' => now()->addDay()])->save();
        $this->actingAs($this->u['client'])->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Here is the info'])->assertCreated();
        $fresh = $ticket->fresh();
        $this->assertSame('in_progress', $fresh->status);
        $this->assertNull($fresh->waiting_reason);
    }

    public function test_confirming_and_reopening_a_resolution_respects_the_window(): void
    {
        $ticket = $this->newTicket();
        $ticket->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'resolution_summary' => 'Done', 'resolution_category' => 'fixed'])->save();
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/reopen', [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/reopen', ['reason' => 'Still broken'])->assertOk()->assertJsonPath('ticket.status', 'triage');
        $this->assertSame(1, $ticket->fresh()->reopened_count);

        $ticket->refresh()->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/confirm')->assertOk()->assertJsonPath('ticket.status', 'closed');
        $ticket->refresh()->forceFill(['closed_at' => CarbonImmutable::now()->subDays(15)])->save();
        $this->actingAs($this->u['client'])->postJson('/api/agency/support/tickets/'.$ticket->ref.'/reopen', ['reason' => 'Late'])->assertUnprocessable();
    }

    public function test_secrets_are_flagged_for_staff_not_redacted(): void
    {
        $response = $this->submit('client', ['description' => 'Login is password: hunter2hunter2 thanks'])->assertCreated();
        $this->assertNotEmpty($response->json('warnings'));
        $ticket = Ticket::query()->where('ref', $response->json('ticket.ref'))->firstOrFail();
        $this->assertNotNull($ticket->secret_flagged_at);
        $this->assertStringContainsString('hunter2hunter2', $ticket->description); // never silently changed
        $this->assertStringNotContainsString('hunter2hunter2', json_encode($this->actingAs($this->staff(['triage']))->getJson('/api/staff/tickets')->json('tickets')));
        $scan = $this->actingAs($this->u['client'])->postJson('/api/support/scan-text', ['text' => 'token: abcdefghijklmnop1234'])->assertOk();
        $this->assertContains('api_key', $scan->json('found'));
    }

    public function test_links_are_attachments_with_visibility(): void
    {
        $triage = $this->staff(['triage']);
        $ticket = $this->newTicket();
        $this->actingAs($this->u['client'])->postJson("/api/tickets/{$ticket->id}/links", ['url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->actingAs($this->u['client'])->postJson("/api/tickets/{$ticket->id}/links", ['url' => 'https://www.loom.com/share/abc', 'title' => 'Screen recording'])->assertCreated()->assertJsonPath('attachment.kind', 'link');
        $this->actingAs($this->u['client'])->postJson("/api/tickets/{$ticket->id}/links", ['url' => 'https://example.com/x', 'is_internal' => true])->assertForbidden();
        $this->actingAs($triage)->postJson("/api/tickets/{$ticket->id}/links", ['url' => 'https://example.com/staff', 'is_internal' => true])->assertCreated();
        $this->assertCount(1, $this->actingAs($this->u['owner'])->getJson("/api/tickets/{$ticket->id}/attachments")->json('attachments'));
        $this->assertCount(2, $this->actingAs($triage)->getJson("/api/tickets/{$ticket->id}/attachments")->json('attachments'));
    }

    public function test_the_staff_queue_is_limited_by_capability_and_views_count(): void
    {
        $triage = $this->staff(['triage']);
        $plain = $this->staff([], 'No capability');
        $a = $this->newTicket();
        $b = $this->newTicket(['title' => 'Second']);
        $b->forceFill(['assigned_to_id' => $plain->id, 'status' => 'assigned'])->save();

        $all = $this->actingAs($triage)->getJson('/api/staff/tickets')->assertOk();
        $this->assertSame(2, $all->json('counts.all'));
        $this->assertSame(1, $all->json('counts.unassigned'));
        $this->assertSame(1, $all->json('counts.new'));
        $mine = $this->actingAs($plain)->getJson('/api/staff/tickets')->assertOk()->json('tickets');
        $this->assertSame([$b->ref], array_column($mine, 'ref'));
        $this->actingAs($plain)->getJson('/api/staff/tickets/'.$a->ref)->assertNotFound();
        $this->actingAs($this->u['client'])->getJson('/api/staff/tickets')->assertForbidden();
        $this->assertCount(1, $this->actingAs($triage)->getJson('/api/staff/tickets?view=unassigned')->json('tickets'));
    }

    public function test_only_an_admin_sets_capabilities_and_admin_holds_them_all(): void
    {
        $person = $this->staff([]);
        $this->actingAs($this->staff(['triage']))->patchJson("/api/staff/people/{$person->id}/capabilities", ['capabilities' => ['qa']])->assertForbidden();
        $this->actingAs($this->u['admin'])->patchJson("/api/staff/people/{$person->id}/capabilities", ['capabilities' => ['qa', 'nope']])->assertUnprocessable();
        $this->actingAs($this->u['admin'])->patchJson("/api/staff/people/{$person->id}/capabilities", ['capabilities' => ['qa', 'builder']])->assertOk();
        $this->assertSame(['qa', 'builder'], $person->fresh()->staff_capabilities);
        $people = collect($this->actingAs($this->u['admin'])->getJson('/api/staff/people')->json('people'));
        $this->assertCount(5, $people->firstWhere('id', $this->u['admin']->id)['capabilities']);
    }

    public function test_existing_ticket_flows_are_unchanged(): void
    {
        $ticket = $this->makeTicket();
        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$ticket->id}/status", ['status' => 'in_progress'])->assertOk();
        $this->assertSame('in_progress', $ticket->fresh()->status);
        $this->assertFalse($ticket->fresh()->usesLifecycle());
        $this->assertSame(1, DB::table('ticket_allocations')->where('ticket_id', $ticket->id)->count() > 0 ? 1 : 0);
    }

    public function test_files_on_support_tickets_follow_tenant_and_visibility_rules(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $triage = $this->staff(['triage']);
        $outsider = $this->outsider();
        $ticket = $this->newTicket();
        $png = fn (string $name) => \Illuminate\Http\UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $public = $this->actingAs($this->u['client'])->post("/api/tickets/{$ticket->id}/attachments", ['files' => [$png('public.png')]], ['Accept' => 'application/json'])->assertCreated()->json('attachments.0.id');
        $internal = $this->actingAs($triage)->post("/api/tickets/{$ticket->id}/attachments", ['files' => [$png('internal.png')], 'comment' => 'staff note', 'is_internal' => true], ['Accept' => 'application/json'])->assertCreated()->json('attachments.0.id');

        // The owner of the ticket's agency reads the public file; the internal one does not exist for them.
        $this->actingAs($this->u['owner'])->get("/api/attachments/{$public}/download")->assertOk();
        $this->actingAs($this->u['owner'])->get("/api/attachments/{$internal}/download")->assertNotFound();
        // Another agency, and a member of staff with no capability, get nothing at all.
        $this->actingAs($outsider)->get("/api/attachments/{$public}/download")->assertNotFound();
        $this->actingAs($this->staff([]))->get("/api/attachments/{$public}/download")->assertNotFound();
        $this->actingAs($triage)->get("/api/attachments/{$internal}/download")->assertOk();
        // A file cannot be added to a ticket of another agency.
        $this->actingAs($outsider)->post("/api/tickets/{$ticket->id}/attachments", ['files' => [$png('x.png')]], ['Accept' => 'application/json'])->assertNotFound();
    }}
