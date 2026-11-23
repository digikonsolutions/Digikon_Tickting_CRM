<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/**
 * Two agencies, a shared employee pool and an admin. Every agency-facing endpoint is called as agency A's owner and
 * client, and nothing in any response may reveal the platform, the admin side, or agency B.
 */
class WhiteLabelLeakTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private Agency $a;

    private Agency $b;

    private User $ownerA;

    private User $clientA;

    private User $ownerB;

    private User $admin;

    private User $shared;

    private WorkItem $project;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWeek();
        config(['scheduling.require_timeline_review' => true]);
        $this->a = Agency::create(['name' => 'Northwind Legal Entity Ltd', 'slug' => 'northwind']);
        $this->a->branding()->create(['display_name' => 'Northwind']);
        $this->b = Agency::create(['name' => 'Sunrise Rival Studio', 'slug' => 'sunrise-rival']);
        $this->b->branding()->create(['display_name' => 'Sunrise']);
        $this->ownerA = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $this->a->id, 'name' => 'Olive Owner', 'is_active' => true]);
        $this->clientA = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $this->a->id, 'name' => 'Cara Client', 'is_active' => true]);
        $this->ownerB = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $this->b->id, 'name' => 'Bruno Rival', 'email' => 'bruno@sunrise-rival.test', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => Role::Admin, 'name' => 'Ada Backoffice', 'email' => 'ada@platform.test', 'is_active' => true]);
        $this->shared = $this->makeEmployee('Sam Shared');
        $this->makeEmployee('Pat Pool');                                                     // a second employee nobody holds
        // Agency B holds an employee and has work of its own.
        $this->hold($this->makeEmployee('Zed Rivalhire'), $this->b, $this->ownerB);
        $rivalProject = $this->book($this->hold($this->makeEmployee('Zoe Rivalhire'), $this->b, $this->ownerB), 'in_progress', [[$this->today->addDays(3), 2]], null, 'Sunrise secret project');

        // Agency A: the shared employee is held, with a project and a ticket the admin comments on and confirms.
        $assignment = $this->hold($this->shared, $this->a, $this->ownerA);
        $this->project = $this->actingAs($this->ownerA)->postJson('/api/agency/projects', [
            'title' => 'Website', 'description' => 'd', 'estimated_hours' => 6, 'start_date' => $this->monday->addDays(7)->toDateString(),
            'due_date' => $this->monday->addDays(8)->toDateString(), 'assignment_id' => $assignment->id,
        ])->assertCreated()->json('project.id') ? WorkItem::latest('id')->firstOrFail() : throw new \RuntimeException;
        $this->ticket = Ticket::findOrFail($this->actingAs($this->ownerA)->postJson("/api/agency/projects/{$this->project->id}/tickets", ['title' => 'Header', 'description' => 'd', 'estimated_hours' => 2])->json('ticket.id'));
        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$this->project->id}/timeline/request-changes", ['message' => 'Please adjust.'])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/admin/projects/{$this->project->id}/timeline/confirm")->assertOk();
        $this->actingAs($this->admin)->postJson("/api/tickets/{$this->ticket->id}/comments", ['body' => 'Looks good from our side.'])->assertCreated();
        unset($rivalProject);
    }

    /** @return list<string> */
    private function forbidden(): array
    {
        return [
            'ClientHub', 'Administrator', 'administrator', 'Shared employee', 'shared employee', 'another agency', 'other agency', ' platform',
            'Sunrise', 'sunrise', 'Bruno Rival', 'bruno@sunrise-rival.test', 'Zed Rivalhire', 'Zoe Rivalhire', 'secret project',
            'Ada Backoffice', 'ada@platform.test', 'Legal Entity', '"role":"admin"', '"actor_role":"admin"',
        ];
    }

    private function assertClean(string $label, string $body): void
    {
        foreach ($this->forbidden() as $needle) {
            $this->assertStringNotContainsString($needle, $body, "{$label} leaks “{$needle}”");
        }
        // Other agency's ids never appear next to an agency_id key.
        $this->assertDoesNotMatchRegularExpression('/"agency_id":'.$this->b->id.'\b/', $body, "{$label} leaks another agency id");
    }

    public function test_every_agency_facing_endpoint_is_clean_for_the_owner_and_the_client(): void
    {
        $common = ['/api/auth/me', '/api/agency/directory', '/api/agency/team', '/api/agency/employees/available', '/api/agency/assignments', '/api/agency/projects',
            '/api/agency/tickets', '/api/agency/dashboard', '/api/agency/branding', '/api/agency/requests', '/api/notifications',
            "/api/agency/projects/{$this->project->id}", "/api/agency/projects/{$this->project->id}/tickets", "/api/agency/projects/{$this->project->id}/timeline",
            "/api/tickets/{$this->ticket->id}/comments", "/api/tickets/{$this->ticket->id}/timeline", '/api/agency/employees/summary'];
        $ownerOnly = ['/api/agency/users', '/api/agency/clients', "/api/agency/projects/{$this->project->id}/activity"];

        foreach ([[$this->ownerA, array_merge($common, $ownerOnly)], [$this->clientA, $common]] as [$user, $paths]) {
            $this->actingAs($user);
            foreach ($paths as $path) {
                $response = $this->getJson($path);
                if (in_array($response->status(), [403, 404], true)) {
                    continue;                                                     // not for this role: nothing to leak
                }
                $this->assertContains($response->status(), [200], "{$user->role->value} {$path} returned {$response->status()}");
                $this->assertClean("{$user->role->value} GET {$path}", $response->getContent());
            }
        }
    }

    public function test_internal_staff_are_presented_as_the_agencys_support(): void
    {
        $comments = $this->actingAs($this->ownerA)->getJson("/api/tickets/{$this->ticket->id}/comments")->assertOk();
        $author = collect($comments->json('comments'))->firstWhere('body', 'Looks good from our side.');
        $this->assertSame(['id' => null, 'name' => 'Northwind Support', 'role' => 'support'], $author['user']);

        $timeline = $this->getJson("/api/agency/projects/{$this->project->id}/timeline")->assertOk();
        $actors = collect($timeline->json('events'))->pluck('actor')->unique()->all();
        $this->assertContains('Northwind Support', $actors);
        $this->assertNotContains('Ada Backoffice', $actors);
        $this->assertSame('Northwind Support', $timeline->json('timeline.project_manager.name'));
        $this->assertSame('Northwind Support', $timeline->json('timeline.confirmed_by'));
        $this->assertNull($timeline->json('timeline.project_manager.id'));

        // The admin still sees their own name.
        $this->actingAs($this->admin)->getJson("/api/admin/projects/{$this->project->id}/timeline")->assertOk()->assertJsonPath('timeline.project_manager.name', 'Ada Backoffice');
    }

    public function test_the_employee_pool_never_says_shared_or_names_an_agency(): void
    {
        $available = $this->actingAs($this->ownerA)->getJson('/api/agency/employees/available')->assertOk()->json('employees');

        $this->assertNotEmpty($available);
        foreach ($available as $employee) {
            $this->assertSame(['email', 'id', 'name', 'role'], collect($employee)->only(['email', 'id', 'name', 'role'])->keys()->sort()->values()->all());
            $this->assertArrayNotHasKey('agency_id', $employee);
            $this->assertSame('team_member', $employee['role']);
        }
        $this->assertNotContains('Zed Rivalhire', array_column($available, 'name'));        // B's held employees are not offered
        $this->postJson('/api/agency/assignments', ['employee_id' => User::where('name', 'Zed Rivalhire')->value('id')])->assertUnprocessable()
            ->assertJsonPath('errors.employee_id.0', 'This team member is not available.');
    }

    public function test_errors_to_agency_users_use_neutral_wording(): void
    {
        $this->actingAs($this->ownerA);
        $bodies = [
            $this->postJson('/api/agency/requests', ['type' => 'more_hours'])->getContent(),
            $this->postJson('/api/agency/assignments', ['employee_id' => 999999])->getContent(),
            $this->getJson('/api/agency/clients/999999')->getContent(),
            $this->postJson('/api/agency/projects', [])->getContent(),
            $this->getJson('/api/admin/timeline-reviews')->getContent(),                                           // forbidden
        ];
        foreach ($bodies as $i => $body) {
            $this->assertClean("error body {$i}", $body);
        }
    }
}
