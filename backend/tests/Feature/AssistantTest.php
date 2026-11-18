<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The in-app assistant. "Today" is Wednesday 2026-03-04, so "this week" is Mon 03-02 .. Sun 03-08:
 * six working days = 36 h capacity.
 *
 *   P1 agency A, client A1, e1, in_progress, due 03-03 (1 day late)   e1 booked 3 x 6 h = 18 h
 *   P2 agency A, client A2, e2, scheduled,   due 03-10                e2 booked 4 h
 *   P3 agency B, client B,  e3, in_progress, due 03-20                e3 booked 6 x 7 h = 42 h
 *   Tickets: ALPH-1 (P1, waiting on client), ALPH-2 (P1, done), ALPH-3 (P2, todo), BETA-1 (P3, waiting on client)
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $u = [];

    private Agency $a;

    private Agency $b;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-04 10:00:00');
        CarbonImmutable::setTestNow('2026-03-04 10:00:00');

        $this->a = Agency::create(['name' => 'Alpha', 'ticket_prefix' => 'ALPH']);
        $this->b = Agency::create(['name' => 'Beta', 'ticket_prefix' => 'BETA']);
        $make = fn (string $k, string $name, Role $role, ?Agency $agency = null) => $this->u[$k] = User::factory()->create(['name' => $name, 'role' => $role, 'agency_id' => $agency?->id]);
        $make('admin', 'Ada Admin', Role::Admin);
        $make('lead', 'Lee Lead', Role::TeamLead);
        $make('ownerA', 'Olive Alpha', Role::AgencyOwner, $this->a);
        $make('ownerB', 'Otto Beta', Role::AgencyOwner, $this->b);
        $make('clientA1', 'Carl One', Role::AgencyClient, $this->a);
        $make('clientA2', 'Cara Two', Role::AgencyClient, $this->a);
        $make('clientB', 'Bea Client', Role::AgencyClient, $this->b);
        $make('memberA', 'Mia Member', Role::AgencyMember, $this->a);
        $make('e1', 'Eve One', Role::Employee);
        $make('e2', 'Eli Two', Role::Employee);
        $make('e3', 'Eva Three', Role::Employee);
        $make('e4', 'Ed Free', Role::Employee);

        foreach ([['ownerA', 'e1', $this->a], ['clientA1', 'e2', $this->a], ['ownerB', 'e3', $this->b]] as [$holder, $employee, $agency]) {
            DB::table('employee_assignments')->insert([
                'holder_id' => $this->u[$holder]->id, 'agency_id' => $agency->id, 'employee_id' => $this->u[$employee]->id,
                'status' => 'active', 'active_employee_id' => $this->u[$employee]->id,
                'started_at' => '2026-03-01 00:00:00', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
            ]);
        }

        $p1 = $this->project($this->a, 'clientA1', 'e1', 'P1 Website', 'in_progress', '2026-03-03');
        $p2 = $this->project($this->a, 'clientA2', 'e2', 'P2 Landing page', 'scheduled', '2026-03-10');
        $p3 = $this->project($this->b, 'clientB', 'e3', 'P3 Beta app', 'in_progress', '2026-03-20');
        $this->book($p1, 'e1', ['2026-03-02', '2026-03-03', '2026-03-04'], 6);
        $this->book($p2, 'e2', ['2026-03-05'], 4);
        $this->book($p3, 'e3', ['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-07'], 7);

        $t1 = $this->ticket($p1, $this->a, 1, 'Approve copy', 'waiting_on_client');
        $this->ticket($p1, $this->a, 2, 'Build header', 'done');
        $this->ticket($p2, $this->a, 3, 'Wireframes', 'todo');
        $this->ticket($p3, $this->b, 1, 'Beta question', 'waiting_on_client');

        DB::table('time_entries')->insert([
            'ticket_id' => $t1, 'work_item_id' => $p1, 'user_id' => $this->u['e1']->id, 'entry_date' => '2026-03-03', 'minutes' => 90,
            'source' => 'manual', 'created_at' => '2026-03-03 10:00:00', 'updated_at' => '2026-03-03 10:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function project(Agency $agency, string $client, string $employee, string $title, string $status, string $due): int
    {
        return DB::table('work_items')->insertGetId([
            'agency_id' => $agency->id, 'client_id' => $this->u[$client]->id, 'employee_id' => $this->u[$employee]->id,
            'title' => $title, 'description' => 'x', 'estimated_hours' => 20, 'start_date' => '2026-03-02', 'due_date' => $due,
            'status' => $status, 'created_at' => '2026-03-01 09:00:00', 'updated_at' => '2026-03-01 09:00:00',
        ]);
    }

    /** @param list<string> $days */
    private function book(int $project, string $employee, array $days, float $hours): void
    {
        foreach ($days as $day) {
            DB::table('work_item_allocations')->insert(['work_item_id' => $project, 'employee_id' => $this->u[$employee]->id, 'work_date' => $day, 'hours' => $hours, 'created_at' => $day, 'updated_at' => $day]);
        }
    }

    private function ticket(int $project, Agency $agency, int $number, string $title, string $status): int
    {
        return DB::table('tickets')->insertGetId([
            'agency_id' => $agency->id, 'work_item_id' => $project, 'number' => $number, 'title' => $title, 'description' => 'x',
            'estimated_hours' => 2, 'priority' => 'normal', 'status' => $status, 'position' => $number,
            'created_at' => '2026-03-02 09:00:00', 'updated_at' => '2026-03-02 09:00:00',
        ]);
    }

    /** @return list<string> */
    private function keys(string $who): array
    {
        $categories = $this->actingAs($this->u[$who])->getJson('/api/assistant/questions')->assertOk()
            ->assertJsonStructure(['categories' => ['*' => ['name', 'questions' => ['*' => ['key', 'label', 'description', 'params']]]]])
            ->json('categories');

        return collect($categories)->flatMap(fn (array $c) => array_column($c['questions'], 'key'))->all();
    }

    private function ask(string $who, string $key, array $params = [])
    {
        return $this->actingAs($this->u[$who])->postJson('/api/assistant/ask', ['question_key' => $key, 'params' => $params]);
    }

    /** @return array<string, mixed> */
    private function block(array $answer, string $type): array
    {
        return collect($answer['blocks'])->firstWhere('type', $type) ?? [];
    }

    private function stat(array $answer, string $label): mixed
    {
        return collect($this->block($answer, 'stats')['items'] ?? [])->firstWhere('label', $label)['value'] ?? null;
    }

    // ---- access and per-role question lists -----------------------------------------------------------

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/assistant/questions')->assertUnauthorized();
        $this->postJson('/api/assistant/ask', ['question_key' => 'my_account'])->assertUnauthorized();
    }

    public function test_every_role_has_its_own_questions(): void
    {
        $prefixes = ['admin' => 'admin_', 'ownerA' => 'owner_', 'clientA1' => 'client_', 'memberA' => 'member_', 'e1' => 'employee_'];
        foreach ($prefixes as $who => $prefix) {
            $keys = $this->keys($who);
            $this->assertContains('my_account', $keys, $who);
            $own = array_filter($keys, fn (string $k): bool => $k !== 'my_account');
            $this->assertNotEmpty($own, $who);
            foreach ($own as $key) {
                $this->assertStringStartsWith($prefix, $key, "{$who} was offered {$key}");
            }
        }
        $this->assertSame(['my_account'], $this->keys('lead'));
    }

    public function test_a_role_cannot_ask_another_roles_question(): void
    {
        $this->ask('ownerA', 'admin_platform_overview')->assertNotFound();
        $this->ask('clientA1', 'owner_agency_overview')->assertNotFound();
        $this->ask('e1', 'client_my_projects')->assertNotFound();
        $this->ask('admin', 'nope')->assertNotFound();
        $this->actingAs($this->u['admin'])->postJson('/api/assistant/ask', [])->assertStatus(422)->assertJsonValidationErrors('question_key');
    }

    public function test_users_of_a_suspended_agency_get_403(): void
    {
        $this->b->update(['status' => 'suspended']);
        $this->actingAs($this->u['ownerB'])->getJson('/api/assistant/questions')->assertForbidden();
        $this->ask('ownerB', 'owner_agency_overview')->assertForbidden();
    }

    public function test_answers_have_the_documented_shape(): void
    {
        $this->ask('ownerA', 'owner_agency_overview')->assertOk()
            ->assertJsonStructure(['question_key', 'title', 'generated_at', 'blocks' => [['type']], 'empty_message'])
            ->assertJsonPath('question_key', 'owner_agency_overview');
        $this->ask('lead', 'my_account')->assertOk()->assertJsonPath('blocks.0.type', 'stats');
    }

    // ---- owner --------------------------------------------------------------------------------------------

    public function test_owner_agency_overview_is_scoped_to_their_agency(): void
    {
        $answer = $this->ask('ownerA', 'owner_agency_overview')->assertOk()->json();

        $this->assertSame(2, $this->stat($answer, 'Open projects'));
        $this->assertSame(1, $this->stat($answer, 'Overdue projects'));
        $this->assertSame(2, $this->stat($answer, 'Specialists held'));
        $this->assertSame(1, $this->stat($answer, 'Waiting on a client')); // ALPH-1 only, never BETA-1
    }

    public function test_owner_employee_capacity_uses_the_36_hour_week(): void
    {
        $all = $this->ask('ownerA', 'owner_employee_capacity')->assertOk()->json();
        $items = collect($this->block($all, 'progress')['items'])->keyBy('label');
        $this->assertEquals(18, $items['Eve One']['used']);
        $this->assertEquals(36, $items['Eve One']['capacity']);
        $this->assertEquals(50, $items['Eve One']['percent']);
        $this->assertSame('healthy', $items['Eve One']['band']);
        $this->assertEquals(4, $items['Eli Two']['used']);
        $this->assertArrayNotHasKey('Eva Three', $items->all());

        $one = $this->ask('ownerA', 'owner_employee_capacity', ['employee_id' => $this->u['e1']->id])->assertOk()->json();
        $this->assertCount(1, $this->block($one, 'progress')['items']);
        $this->assertEquals(18, $this->block($one, 'progress')['items'][0]['used']);

        // the options are the owner's own specialists; another agency's is refused
        $options = collect($this->actingAs($this->u['ownerA'])->getJson('/api/assistant/questions')->json('categories'))
            ->flatMap(fn ($c) => $c['questions'])->firstWhere('key', 'owner_employee_capacity')['params'][0]['options'];
        $this->assertEqualsCanonicalizing(['Eve One', 'Eli Two'], array_column($options, 'label'));
        $this->ask('ownerA', 'owner_employee_capacity', ['employee_id' => $this->u['e3']->id])->assertStatus(422)->assertJsonValidationErrors('params.employee_id');
    }

    public function test_owner_lists_overdue_projects_and_waiting_tickets(): void
    {
        $overdue = $this->block($this->ask('ownerA', 'owner_overdue_projects')->assertOk()->json(), 'list')['items'];
        $this->assertSame('P1 Website', $overdue[0]['title']);
        $this->assertSame('1 days late', $overdue[0]['meta']);
        $this->assertNotContains('P3 Beta app', array_column($overdue, 'title'));

        $waiting = $this->block($this->ask('ownerA', 'owner_waiting_on_client')->assertOk()->json(), 'list')['items'];
        $this->assertSame(['ALPH-1 · Approve copy'], array_column($waiting, 'title'));
        $this->assertSame('/tickets/ALPH-1', $waiting[0]['href']);

        $clients = $this->block($this->ask('ownerA', 'owner_client_workload')->assertOk()->json(), 'table');
        $this->assertEqualsCanonicalizing(['Carl One', 'Cara Two'], array_column($clients['rows'], 0));
    }

    // ---- client -------------------------------------------------------------------------------------------

    public function test_client_only_sees_their_own_projects_and_tickets(): void
    {
        $projects = $this->block($this->ask('clientA1', 'client_my_projects')->assertOk()->json(), 'list')['items'];
        $this->assertSame(['P1 Website'], array_column($projects, 'title'));
        $this->assertStringContainsString('1 of 2 tickets done', $projects[0]['meta']);

        $this->assertSame(['ALPH-1 · Approve copy'], array_column($this->block($this->ask('clientA1', 'client_needs_my_input')->json(), 'list')['items'], 'title'));
        $none = $this->ask('clientA2', 'client_needs_my_input')->assertOk()->json();
        $this->assertSame([], $this->block($none, 'list')['items']);
        $this->assertNotNull($none['empty_message']);

        $deadlines = $this->block($this->ask('clientA2', 'client_upcoming_deadlines')->assertOk()->json(), 'table')['rows'];
        $this->assertSame('P2 Landing page', $deadlines[0][0]);

        $specialists = collect($this->block($this->ask('clientA1', 'client_my_specialists')->assertOk()->json(), 'progress')['items'])->keyBy('label');
        $this->assertEquals(18, $specialists['Eve One']['used']); // only hours on this client's projects
        $this->assertArrayNotHasKey('Eva Three', $specialists->all());
    }

    // ---- employee, member, admin --------------------------------------------------------------------------

    public function test_employee_week_tickets_and_logged_time(): void
    {
        $week = $this->ask('e1', 'employee_my_week')->assertOk()->json();
        $this->assertEquals(18, $this->block($week, 'progress')['items'][0]['used']);
        $this->assertEquals(36, $this->block($week, 'progress')['items'][0]['capacity']);
        $this->assertCount(7, $this->block($week, 'table')['rows']);

        $tickets = array_column($this->block($this->ask('e1', 'employee_open_tickets')->json(), 'list')['items'], 'title');
        $this->assertSame(['ALPH-1 · Approve copy'], $tickets); // the done ticket and other people's tickets are left out

        $logged = $this->ask('e1', 'employee_logged_time')->assertOk()->json();
        $this->assertSame('1.5 h', $this->stat($logged, 'Logged'));
        $this->assertSame('18 h', $this->stat($logged, 'Scheduled'));
    }

    public function test_member_sees_agency_people_counts(): void
    {
        $answer = $this->ask('memberA', 'member_agency_people')->assertOk()->json();
        $this->assertSame(1, $this->stat($answer, 'Owners'));
        $this->assertSame(2, $this->stat($answer, 'Clients'));
    }

    public function test_admin_questions(): void
    {
        $overview = $this->ask('admin', 'admin_platform_overview')->assertOk()->json();
        $this->assertSame(2, $this->stat($overview, 'Active agencies'));
        $this->assertSame(1, $this->stat($overview, 'Overdue projects'));
        $this->assertSame(1, $this->stat($overview, 'Available specialists'));

        $this->assertSame(['Ed Free'], array_column($this->block($this->ask('admin', 'admin_unassigned_employees')->json(), 'list')['items'], 'title'));

        $over = $this->block($this->ask('admin', 'admin_over_capacity')->assertOk()->json(), 'progress')['items'];
        $this->assertSame(['Eva Three'], array_column($over, 'label'));
        $this->assertEquals(116.7, $over[0]['percent']);
        $this->assertSame('high', $over[0]['band']);

        $this->assertSame(['P1 Website'], array_column($this->block($this->ask('admin', 'admin_overdue_projects')->json(), 'list')['items'], 'title'));
        $this->assertSame('Beta', $this->block($this->ask('admin', 'admin_top_agencies')->assertOk()->json(), 'table')['rows'][0][0]);
    }
}
