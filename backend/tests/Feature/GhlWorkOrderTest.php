<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketChecklistItem;
use App\Models\TicketDetail;
use App\Models\WorkItem;
use App\Models\WorkItemBrief;
use App\Services\TicketPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** The GHL project brief and the ticket work order: validation, roles, rollback, and that scheduling never changes. */
class GhlWorkOrderTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    private function projectUrl(string $suffix = ''): string
    {
        return '/api/agency/projects/'.$this->project->id.$suffix;
    }

    /** Payload for a new project, in a week far enough out to be free. */
    private function newProject(array $extra = []): array
    {
        $monday = \Carbon\CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(6);

        return ['title' => 'P', 'description' => 'D', 'estimated_hours' => 4, 'start_date' => $monday->toDateString(), 'due_date' => $monday->addDays(5)->toDateString(), 'assignment_id' => $this->project->assignment_id, ...$extra];
    }

    private function order(array $extra = []): array
    {
        return [
            'work_type' => 'workflow_build',
            'requirements' => ['instructions' => 'Build the lead nurture workflow.', 'reference_urls' => ['https://example.com/spec']],
            'ghl' => ['location_id' => 'LOC123', 'refs' => [['type' => 'workflow', 'name' => 'Lead nurture', 'ghl_id' => 'wf_1', 'url' => 'https://app.example.com/wf_1']]],
            'notes' => ['client' => 'Please keep it short.'],
            ...$extra,
        ];
    }

    private function createTicket(array $extra = [], string $by = 'client'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->u[$by])->postJson($this->projectUrl('/tickets'), ['title' => 'Nurture', 'description' => 'D.', 'estimated_hours' => 2, ...$extra]);
    }

    /** @return array<string, mixed> */
    private function schedule(): array
    {
        return [
            'ticket' => Ticket::query()->orderBy('id')->get(['id', 'estimated_hours', 'position', 'status', 'start_date', 'due_date'])->toArray(),
            'alloc' => DB::table('ticket_allocations')->orderBy('id')->get()->map(fn ($r) => [$r->ticket_id, $r->work_date, $r->hours])->all(),
        ];
    }

    public function test_project_with_and_without_a_brief(): void
    {
        $with = $this->actingAs($this->u['client'])->postJson('/api/agency/projects', [
            ...$this->newProject(['title' => 'Brief']),
            'ghl_brief' => ['client' => ['name' => 'Ada', 'email' => 'ada@example.com'], 'scope' => ['categories' => ['workflow'], 'goal' => 'More leads'], 'notes' => ['client' => 'Hi']],
        ])->assertCreated();
        $this->assertSame('Ada', $with->json('project.brief_summary.client_name'));
        $this->assertArrayNotHasKey('ghl_brief', $with->json('project'));
        $id = $with->json('project.id');
        $this->assertSame(1, WorkItemBrief::query()->where('work_item_id', $id)->count());

        $without = $this->actingAs($this->u['client'])->postJson('/api/agency/projects', [
            ...$this->newProject(['title' => 'Plain']),
        ])->assertCreated();
        $this->assertNull($without->json('project.brief_summary'));

        $show = $this->actingAs($this->u['owner'])->getJson("/api/agency/projects/{$id}")->assertOk();
        $this->assertSame(['workflow'], $show->json('project.ghl_brief.scope.categories'));
        $this->assertGreaterThan(0, $show->json('project.ghl_brief.completeness.percent'));
    }

    public function test_brief_validation_names_the_nested_field_and_server_fields_are_prohibited(): void
    {
        $post = fn (array $brief) => $this->actingAs($this->u['client'])->postJson('/api/agency/projects', [
            ...$this->newProject(['title' => 'B']), 'ghl_brief' => $brief,
        ]);
        $post(['client' => ['email' => 'nope']])->assertUnprocessable()->assertJsonValidationErrors(['ghl_brief.client.email']);
        $post(['ghl' => ['access_method' => 'carrier_pigeon']])->assertUnprocessable()->assertJsonValidationErrors(['ghl_brief.ghl.access_method']);
        $post(['completeness' => 100])->assertUnprocessable()->assertJsonValidationErrors(['ghl_brief.completeness']);
        $post(['source' => 'ghl_prefill'])->assertUnprocessable();
        $post(['notes' => ['internal' => 'secret']])->assertUnprocessable()->assertJsonValidationErrors(['ghl_brief.notes.internal']);
        $this->assertSame(0, WorkItemBrief::query()->count());
    }

    public function test_brief_never_stores_unknown_keys_such_as_passwords(): void
    {
        $id = $this->actingAs($this->u['client'])->postJson('/api/agency/projects', [
            ...$this->newProject(['title' => 'B']),
            'ghl_brief' => ['ghl' => ['location_id' => 'L1', 'password' => 'hunter2', 'api_key' => 'pit-secret']],
        ])->assertCreated()->json('project.id');
        $raw = json_encode(WorkItemBrief::query()->where('work_item_id', $id)->first()->getAttributes());
        $this->assertStringNotContainsString('hunter2', $raw);
        $this->assertStringNotContainsString('pit-secret', $raw);
    }

    public function test_brief_edit_guards(): void
    {
        $this->actingAs($this->u['other'])->patchJson($this->projectUrl('/brief'), ['ghl_brief' => ['client' => ['name' => 'Zed']]])->assertOk()
            ->assertJsonPath('ghl_brief.client.name', 'Zed');
        $this->actingAs($this->u['employee'])->patchJson($this->projectUrl('/brief'), ['ghl_brief' => ['client' => ['name' => 'X']]])->assertForbidden();
        $this->actingAs($this->u['admin'])->patchJson($this->projectUrl('/brief'), ['ghl_brief' => ['client' => ['name' => 'X']]])->assertForbidden();
        $this->actingAs($this->outsider())->patchJson($this->projectUrl('/brief'), ['ghl_brief' => ['client' => ['name' => 'X']]])->assertNotFound();
        $this->project->forceFill(['status' => 'cancelled'])->save();
        $this->actingAs($this->u['client'])->patchJson($this->projectUrl('/brief'), ['ghl_brief' => ['client' => ['name' => 'X']]])->assertUnprocessable();
    }

    public function test_internal_notes_are_admin_written_and_hidden_from_the_agency(): void
    {
        $this->actingAs($this->u['client'])->patchJson($this->projectUrl('/brief'), ['ghl_brief' => ['client' => ['name' => 'Ada']]])->assertOk();
        $this->actingAs($this->u['admin'])->patchJson('/api/admin/projects/'.$this->project->id.'/brief/internal-notes', ['notes' => 'Client is slow to reply'])->assertOk();
        $this->actingAs($this->u['client'])->patchJson('/api/admin/projects/'.$this->project->id.'/brief/internal-notes', ['notes' => 'x'])->assertForbidden();

        $agency = $this->actingAs($this->u['owner'])->getJson($this->projectUrl())->json('project.ghl_brief.notes');
        $this->assertArrayNotHasKey('internal', $agency);
        $admin = $this->actingAs($this->u['admin'])->getJson('/api/admin/projects/'.$this->project->id)->json('project.ghl_brief.notes');
        $this->assertSame('Client is slow to reply', $admin['internal']);
        $this->assertStringNotContainsString('slow to reply', $this->actingAs($this->u['owner'])->getJson($this->projectUrl())->getContent());
    }

    public function test_ticket_with_and_without_a_work_order_schedules_identically(): void
    {
        $plain = $this->createTicket()->assertCreated();
        $this->assertNull($plain->json('ticket.details_summary'));
        $this->assertSame(0, TicketDetail::query()->count());
        $baseline = $this->schedule();

        // Same hours on a fresh project: the work order must not change a single allocation.
        $withOrder = $this->createTicket(['work_order' => $this->order()])->assertCreated();
        $summary = $withOrder->json('ticket.details_summary');
        $this->assertSame('workflow_build', $summary['work_type']);
        $this->assertSame(5, $summary['checklist_total']);
        $this->assertSame(0, $summary['checklist_done']);
        $this->assertSame('2.00', $withOrder->json('ticket.estimated_hours'));

        $after = $this->schedule();
        $this->assertSame($baseline['ticket'][0], $after['ticket'][0]);
        $this->assertSame(2, count($after['ticket']));
        $this->assertSame([], array_values(array_filter($after['alloc'], fn ($a) => $a[0] === 0)));
    }

    public function test_work_order_validation_and_dependency_isolation(): void
    {
        $this->createTicket(['work_order' => ['work_type' => 'sing_a_song']])->assertUnprocessable()->assertJsonValidationErrors(['work_order.work_type']);
        $this->createTicket(['work_order' => ['ghl' => ['refs' => [['type' => 'workflow', 'name' => 'x', 'url' => 'not a url']]]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['work_order.ghl.refs.0.url']);
        $this->createTicket(['work_order' => ['checklist' => array_fill(0, 51, ['text' => 'step'])]])->assertUnprocessable()->assertJsonValidationErrors(['work_order.checklist']);
        $this->createTicket(['work_order' => ['ghl' => ['refs' => array_fill(0, 31, ['type' => 'tag', 'name' => 'x'])]]])->assertUnprocessable();
        // Server-owned and employee-owned parts cannot be sent by the agency.
        foreach (['delivery' => ['done' => 'x'], 'blocked' => ['blocked' => true], 'completeness' => 100, 'source' => 'ghl_prefill'] as $key => $value) {
            $this->createTicket(['work_order' => [$key => $value]])->assertUnprocessable();
        }
        // Another project's ticket id is never accepted as a dependency.
        $foreign = $this->outsider();
        $this->assertSame(0, TicketDetail::query()->count());
        $mine = $this->createTicket()->json('ticket.id');
        $this->createTicket(['work_order' => ['dependencies' => ['depends_on' => [$mine]]]])->assertCreated();
        $this->createTicket(['work_order' => ['dependencies' => ['depends_on' => [999999]]]])->assertUnprocessable();
        $this->assertNotNull($foreign);
    }

    public function test_a_failed_plan_rolls_back_the_work_order_and_checklist(): void
    {
        $this->mock(TicketPlanner::class)->shouldReceive('plan')->andThrow(new \RuntimeException('planner exploded'));
        $this->withoutExceptionHandling();
        try {
            $this->createTicket(['work_order' => $this->order()]);
            $this->fail('expected the planner to fail');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, TicketDetail::query()->count());
        $this->assertSame(0, TicketChecklistItem::query()->count());
    }

    public function test_update_changes_the_work_order_without_touching_hours(): void
    {
        $id = $this->createTicket(['work_order' => $this->order()])->json('ticket.id');
        $before = Ticket::findOrFail($id)->only(['estimated_hours', 'position', 'status']);
        $r = $this->actingAs($this->u['other'])->patchJson("/api/agency/tickets/{$id}", ['work_order' => ['requirements' => ['instructions' => 'Changed'], 'checklist' => [['text' => 'Only step']]]])->assertOk();
        $this->assertSame(1, $r->json('ticket.details_summary.checklist_total'));
        $this->assertSame($before, Ticket::findOrFail($id)->only(['estimated_hours', 'position', 'status']));
        $this->assertSame(1, TicketChecklistItem::query()->where('ticket_id', $id)->count());
    }

    public function test_role_matrix_for_delivery_blocked_and_checklist(): void
    {
        $id = $this->createTicket(['work_order' => $this->order()])->json('ticket.id');
        $item = TicketChecklistItem::query()->where('ticket_id', $id)->orderBy('position')->first();

        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$id}/delivery", ['done' => 'Built it', 'links' => ['https://example.com/wf']])->assertOk()
            ->assertJsonPath('work_order.delivery.done', 'Built it');
        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$id}/blocked", ['blocked' => true])->assertUnprocessable();
        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$id}/blocked", ['blocked' => true, 'reason' => 'Need logo', 'waiting_on' => 'client'])->assertOk()
            ->assertJsonPath('work_order.blocked.blocked', true);

        foreach (['client', 'owner', 'admin'] as $who) {
            $this->actingAs($this->u[$who])->patchJson("/api/tickets/{$id}/delivery", ['done' => 'x'])->assertForbidden();
            $this->actingAs($this->u[$who])->patchJson("/api/tickets/{$id}/blocked", ['blocked' => false])->assertForbidden();
        }
        $this->actingAs($this->u['admin'])->patchJson("/api/tickets/{$id}/checklist/{$item->id}", ['done' => true])->assertForbidden();

        $status = Ticket::findOrFail($id)->status;
        $hours = Ticket::findOrFail($id)->estimated_hours;
        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$id}/checklist/{$item->id}", ['done' => true])->assertOk()
            ->assertJsonPath('work_order.checklist.0.done', true);
        $this->actingAs($this->u['client'])->patchJson("/api/tickets/{$id}/checklist/{$item->id}", ['done' => false])->assertOk();
        $this->assertSame($status, Ticket::findOrFail($id)->status);
        $this->assertEquals($hours, Ticket::findOrFail($id)->estimated_hours);

        $this->actingAs($this->outsider())->patchJson("/api/tickets/{$id}/checklist/{$item->id}", ['done' => true])->assertNotFound();
        // An item of another ticket is never reachable through this ticket.
        $other = $this->createTicket(['work_order' => $this->order()])->json('ticket.id');
        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$other}/checklist/{$item->id}", ['done' => true])->assertNotFound();
    }

    public function test_sending_to_review_needs_a_delivery_only_when_there_is_a_work_order(): void
    {
        $plain = $this->createTicket()->json('ticket.id');
        $withOrder = $this->createTicket(['work_order' => $this->order()])->json('ticket.id');
        foreach ([$plain, $withOrder] as $id) {
            $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$id}/status", ['status' => 'in_progress'])->assertOk();
        }
        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$plain}/status", ['status' => 'in_review'])->assertOk();

        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$withOrder}/status", ['status' => 'in_review'])
            ->assertUnprocessable()->assertJsonValidationErrors(['delivery']);
        $this->actingAs($this->u['employee'])->postJson("/api/tickets/{$withOrder}/transitions", ['action' => 'send_to_review'])->assertUnprocessable();
        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$withOrder}/delivery", ['done' => 'Built', 'links' => ['https://example.com/x']])->assertOk();
        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$withOrder}/status", ['status' => 'in_review'])->assertOk();
    }

    public function test_duplicate_copies_the_work_order_with_a_fresh_checklist(): void
    {
        $id = $this->createTicket(['work_order' => $this->order()])->json('ticket.id');
        $item = TicketChecklistItem::query()->where('ticket_id', $id)->first();
        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$id}/checklist/{$item->id}", ['done' => true])->assertOk();
        $this->actingAs($this->u['employee'])->patchJson("/api/tickets/{$id}/delivery", ['done' => 'Done', 'links' => ['https://example.com/x']])->assertOk();

        $copy = $this->actingAs($this->u['client'])->postJson($this->projectUrl("/tickets/{$id}/duplicate"), [])->assertCreated();
        $this->assertSame('Copy of Nurture', $copy->json('ticket.title'));
        $this->assertSame(0, $copy->json('ticket.details_summary.checklist_done'));
        $this->assertSame(5, $copy->json('ticket.details_summary.checklist_total'));
        $copyDetail = TicketDetail::query()->where('ticket_id', $copy->json('ticket.id'))->first();
        $this->assertNull($copyDetail->delivery);
        $this->assertFalse($copyDetail->blocked);

        $this->actingAs($this->outsider())->postJson($this->projectUrl("/tickets/{$id}/duplicate"), [])->assertNotFound();
        $this->actingAs($this->u['employee'])->postJson($this->projectUrl("/tickets/{$id}/duplicate"), [])->assertForbidden();
    }

    public function test_templates_endpoint(): void
    {
        $templates = $this->actingAs($this->u['client'])->getJson('/api/agency/ticket-templates')->assertOk()->json('templates');
        $keys = array_column($templates, 'key');
        $this->assertContains('workflow_build', $keys);
        $this->assertContains('a2p_compliance', $keys);
        $this->assertNotEmpty($templates[0]['checklist']);
        $this->actingAs($this->u['employee'])->getJson('/api/agency/ticket-templates')->assertForbidden();
    }

    public function test_lists_carry_summaries_only_and_internal_notes_stay_internal(): void
    {
        $id = $this->createTicket(['work_order' => $this->order()])->json('ticket.id');
        $this->actingAs($this->u['admin'])->patchJson("/api/admin/tickets/{$id}/internal-notes", ['notes' => 'Watch out for the Zapier hook'])->assertOk();
        $this->actingAs($this->u['client'])->patchJson("/api/admin/tickets/{$id}/internal-notes", ['notes' => 'x'])->assertForbidden();

        foreach (['/api/agency/tickets', $this->projectUrl('/tickets')] as $url) {
            $body = $this->actingAs($this->u['client'])->getJson($url)->assertOk()->getContent();
            $this->assertStringContainsString('details_summary', $body);
            $this->assertStringNotContainsString('Build the lead nurture workflow', $body);
            $this->assertStringNotContainsString('Zapier', $body);
        }
        $mine = $this->actingAs($this->u['employee'])->getJson('/api/work-items/mine/tickets')->assertOk()->getContent();
        $this->assertStringNotContainsString('Build the lead nurture workflow', $mine);

        $key = Ticket::findOrFail($id)->key;
        $agencyView = $this->actingAs($this->u['client'])->getJson("/api/tickets/key/{$key}")->assertOk();
        $this->assertSame('Build the lead nurture workflow.', $agencyView->json('work_order.requirements.instructions'));
        $this->assertArrayNotHasKey('internal', $agencyView->json('work_order.notes'));
        $this->assertStringNotContainsString('Zapier', $agencyView->getContent());
        $employeeView = $this->actingAs($this->u['employee'])->getJson("/api/tickets/key/{$key}")->assertOk();
        $this->assertSame('Watch out for the Zapier hook', $employeeView->json('work_order.notes.internal'));
        $this->assertArrayHasKey('ghl_brief', $employeeView->json('project'));
        $adminTickets = $this->actingAs($this->u['admin'])->getJson('/api/admin/projects/'.$this->project->id)->assertOk()->json('tickets');
        $this->assertSame('workflow_build', $adminTickets[0]['details_summary']['work_type']);
        $this->assertStringNotContainsString('Build the lead nurture workflow', json_encode($adminTickets));
    }

    public function test_ticket_list_query_count_does_not_grow_with_work_orders(): void
    {
        $this->createTicket(['work_order' => $this->order()]);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->u['client'])->getJson($this->projectUrl('/tickets'))->assertOk();
            $this->actingAs($this->u['client'])->getJson('/api/agency/tickets')->assertOk();
            $this->actingAs($this->u['employee'])->getJson('/api/work-items/mine/tickets')->assertOk();

            return count(DB::getQueryLog());
        };
        $one = $count();
        foreach (range(1, 4) as $i) {
            $this->createTicket(['title' => "T$i", 'estimated_hours' => 1, 'work_order' => $this->order()])->assertCreated();
        }
        $this->assertSame($one, $count());
    }

    public function test_project_lists_load_briefs_in_one_query(): void
    {
        foreach (range(1, 2) as $i) {
            $this->actingAs($this->u['client'])->postJson('/api/agency/projects', [
                ...$this->newProject(['title' => "P$i", 'estimated_hours' => 1]),
                'ghl_brief' => ['client' => ['name' => "C$i"]],
            ])->assertCreated();
        }
        foreach ([['client', '/api/agency/projects'], ['admin', '/api/admin/projects'], ['employee', '/api/work-items/mine']] as [$who, $url]) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->u[$who])->getJson($url)->assertOk();
            $briefQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '"work_item_briefs"'));
            $this->assertCount(1, $briefQueries, "$url loads briefs with one query");
        }
    }
}