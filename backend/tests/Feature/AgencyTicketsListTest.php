<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/** GET /api/agency/tickets: every ticket of the owner's agency (or a client's own projects) with filters. */
class AgencyTicketsListTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWeek();
    }

    private function ticket(WorkItem $project, string $title, string $status = 'todo', string $priority = 'normal'): Ticket
    {
        $ticket = Ticket::create(['work_item_id' => $project->id, 'agency_id' => $project->agency_id, 'title' => $title, 'description' => 'd', 'estimated_hours' => 1, 'position' => 0, 'priority' => $priority]);
        $ticket->forceFill(['status' => $status])->save();

        return $ticket;
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\User, 2: \App\Models\User, 3: WorkItem, 4: WorkItem} */
    private function world(): array
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $ann = $this->makeClient($agency, 'Ann');
        $bob = $this->makeClient($agency, 'Bob');
        $emp = $this->makeEmployee('Emp');
        $annProject = $this->book($this->hold($emp, $agency, $ann), 'in_progress', [[$this->today->addDays(2), 2]], null, 'Ann site');
        $bobProject = $this->book($this->hold($this->makeEmployee('Emp2'), $agency, $bob), 'in_progress', [[$this->today->addDays(2), 2]], null, 'Bob site');

        return [$owner, $ann, $bob, $annProject, $bobProject];
    }

    public function test_the_owner_sees_every_ticket_of_the_agency_and_filters_them(): void
    {
        [$owner, $ann, , $annProject, $bobProject] = $this->world();
        $this->ticket($annProject, 'Fix header', 'in_progress', 'high');
        $this->ticket($annProject, 'Review copy', 'in_review');
        $this->ticket($bobProject, 'Bob form', 'waiting_on_client');
        $this->ticket($bobProject, 'Old', 'cancelled');

        $all = $this->actingAs($owner)->getJson('/api/agency/tickets')->assertOk();
        $this->assertSame(4, $all->json('pagination.total'));
        $this->assertSame('Ann site', collect($all->json('tickets'))->firstWhere('title', 'Fix header')['project_title']);
        $this->assertSame('Ann', collect($all->json('tickets'))->firstWhere('title', 'Fix header')['client_name']);

        $this->getJson('/api/agency/tickets?status=in_review')->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.title', 'Review copy');
        $this->getJson('/api/agency/tickets?waiting=you')->assertJsonCount(2, 'tickets');
        $this->getJson('/api/agency/tickets?priority=high')->assertJsonCount(1, 'tickets');
        $this->getJson("/api/agency/tickets?project_id={$bobProject->id}")->assertJsonCount(2, 'tickets');
        $this->getJson("/api/agency/tickets?client_id={$ann->id}")->assertJsonCount(2, 'tickets');
        $this->getJson('/api/agency/tickets?search=header')->assertJsonCount(1, 'tickets');
        $this->getJson('/api/agency/tickets?search=%25')->assertJsonCount(0, 'tickets');
        $this->getJson('/api/agency/tickets?per_page=3&page=2')->assertJsonCount(1, 'tickets')->assertJsonPath('pagination.last_page', 2);
        $this->getJson('/api/agency/tickets?status=nonsense')->assertUnprocessable();
    }

    public function test_search_finds_a_ticket_by_its_key(): void
    {
        [$owner, , , $annProject] = $this->world();
        $annProject->agency->update(['ticket_prefix' => 'NWD']);          // before any ticket exists: the prefix is immutable afterwards
        $this->ticket($annProject, 'Fix header')->forceFill(['number' => 7])->save();

        $this->actingAs($owner)->getJson('/api/agency/tickets?search=NWD-7')->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.title', 'Fix header');
    }

    public function test_a_client_only_sees_tickets_on_their_own_projects(): void
    {
        [, $ann, $bob, $annProject, $bobProject] = $this->world();
        $this->ticket($annProject, 'Ann ticket');
        $this->ticket($bobProject, 'Bob ticket');

        $this->actingAs($ann)->getJson('/api/agency/tickets')->assertJsonCount(1, 'tickets')->assertJsonPath('tickets.0.title', 'Ann ticket');
        $this->getJson("/api/agency/tickets?project_id={$bobProject->id}")->assertJsonCount(0, 'tickets');
        $this->actingAs($bob)->getJson('/api/agency/tickets?client_id='.$ann->id)->assertJsonPath('tickets.0.title', 'Bob ticket');   // client_id is ignored for clients
    }

    public function test_other_agencies_never_leak_and_other_roles_are_refused(): void
    {
        [$owner, , , $annProject] = $this->world();
        $this->ticket($annProject, 'Ours');
        $rival = $this->makeAgency('Rival');
        $rivalOwner = $this->makeOwner($rival, 'Rival Owner');
        $rivalProject = $this->book($this->hold($this->makeEmployee('Zed'), $rival, $this->makeClient($rival, 'RC')), 'in_progress', [[$this->today->addDays(3), 2]], null, 'Secret project');
        $this->ticket($rivalProject, 'Secret ticket');

        $json = $this->actingAs($owner)->getJson('/api/agency/tickets')->assertOk();
        $this->assertSame(['Ours'], array_column($json->json('tickets'), 'title'));
        $this->getJson("/api/agency/tickets?project_id={$rivalProject->id}")->assertJsonCount(0, 'tickets');
        $this->assertStringNotContainsString('Secret', $json->getContent());
        $this->actingAs($rivalOwner)->getJson('/api/agency/tickets')->assertJsonPath('tickets.0.title', 'Secret ticket');

        $this->actingAs($this->makeEmployee('Staff'))->getJson('/api/agency/tickets')->assertForbidden();
        auth()->forgetGuards();
        $this->getJson('/api/agency/tickets')->assertUnauthorized();
    }
}
