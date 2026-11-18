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
 * Reports on a fixed fixture. "Today" is Thursday 2026-03-05 and the default test range is the ISO week
 * Mon 2026-03-02 .. Sun 2026-03-08, which has six working days = 36 h of capacity per employee.
 *
 * Projects (all inside the range):
 *   P1 agency A, client A1, e1, in_progress, due 03-06   e1 booked 4 x 6 h = 24 h
 *   P2 agency A, client A2, e1, completed (03-05), due 03-04   e1 booked 6 h
 *   P3 agency A, client A1, e2, scheduled, due 03-03 (overdue)   e2 booked 3 h
 *   P4 agency B, client B,  e4, in_progress, due 03-07   e4 booked 6 x 7 h = 42 h (over capacity)
 *   P5 agency A, client A1, e1, cancelled   e1 booked 6 h that must NOT count
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private const RANGE = ['from' => '2026-03-02', 'to' => '2026-03-08'];

    /** @var array<string, User> */
    private array $u = [];

    private Agency $a;

    private Agency $b;

    private Agency $c;

    /** @var array<string, int> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-05 10:00:00');
        CarbonImmutable::setTestNow('2026-03-05 10:00:00');
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function user(string $key, string $name, Role $role, ?Agency $agency = null, bool $active = true): User
    {
        return $this->u[$key] = User::factory()->create(['name' => $name, 'role' => $role, 'agency_id' => $agency?->id, 'is_active' => $active]);
    }

    private function assignment(User $holder, User $employee, Agency $agency, string $status, string $start, ?string $end = null): void
    {
        DB::table('employee_assignments')->insert([
            'holder_id' => $holder->id, 'agency_id' => $agency->id, 'employee_id' => $employee->id, 'status' => $status,
            'active_employee_id' => $status === 'active' ? $employee->id : null,
            'started_at' => $start, 'ended_at' => $end, 'created_at' => $start, 'updated_at' => $start,
        ]);
    }

    private function project(string $key, Agency $agency, ?User $client, User $employee, string $status, string $start, string $due, float $hours, string $updated = '2026-03-02 09:00:00'): void
    {
        $this->p[$key] = DB::table('work_items')->insertGetId([
            'agency_id' => $agency->id, 'client_id' => $client?->id, 'employee_id' => $employee->id, 'title' => "Project {$key}", 'description' => 'x',
            'estimated_hours' => $hours, 'start_date' => $start, 'due_date' => $due, 'status' => $status,
            'created_at' => '2026-03-01 09:00:00', 'updated_at' => $updated,
        ]);
    }

    /** @param list<string> $days */
    private function book(string $project, User $employee, array $days, float $hours): void
    {
        foreach ($days as $day) {
            DB::table('work_item_allocations')->insert([
                'work_item_id' => $this->p[$project], 'employee_id' => $employee->id, 'work_date' => $day, 'hours' => $hours,
                'created_at' => $day, 'updated_at' => $day,
            ]);
        }
    }

    private int $ticketNumber = 0;

    private function ticket(string $project, Agency $agency, string $title, string $status, string $priority, float $hours, string $created, string $updated): void
    {
        DB::table('tickets')->insert([
            'agency_id' => $agency->id, 'work_item_id' => $this->p[$project], 'number' => ++$this->ticketNumber, 'title' => $title,
            'description' => 'x', 'estimated_hours' => $hours, 'priority' => $priority, 'status' => $status, 'position' => $this->ticketNumber,
            'created_at' => $created, 'updated_at' => $updated,
        ]);
    }

    private function buildFixture(): void
    {
        $this->a = Agency::create(['name' => 'Alpha', 'ticket_prefix' => 'ALPH']);
        $this->b = Agency::create(['name' => 'Beta', 'ticket_prefix' => 'BETA']);
        $this->c = Agency::create(['name' => 'Gamma', 'ticket_prefix' => 'GAMM', 'status' => 'suspended']);

        $this->user('admin', 'Ada Admin', Role::Admin);
        $this->user('ownerA', 'Olive Alpha', Role::AgencyOwner, $this->a);
        $this->user('clientA1', 'Carl One', Role::AgencyClient, $this->a);
        $this->user('clientA2', 'Cara Two', Role::AgencyClient, $this->a);
        $this->user('ownerB', 'Otto Beta', Role::AgencyOwner, $this->b);
        $this->user('clientB', 'Bea Client', Role::AgencyClient, $this->b);
        $this->user('ownerC', 'Gina Gamma', Role::AgencyOwner, $this->c);
        $this->user('memberA', 'Mia Member', Role::AgencyMember, $this->a);
        $this->user('e1', 'Eve One', Role::Employee);
        $this->user('e2', 'Eli Two', Role::Employee);
        $this->user('e3', 'Ed Inactive', Role::Employee, null, false);
        $this->user('e4', 'Eva Four', Role::Employee);
        $this->user('e5', '=cmd|calc', Role::Employee);

        $this->assignment($this->u['ownerA'], $this->u['e1'], $this->a, 'active', '2026-03-01 00:00:00');
        $this->assignment($this->u['clientA1'], $this->u['e2'], $this->a, 'active', '2026-03-01 00:00:00');
        $this->assignment($this->u['ownerB'], $this->u['e4'], $this->b, 'active', '2026-03-01 00:00:00');
        $this->assignment($this->u['ownerA'], $this->u['e3'], $this->a, 'ended', '2026-03-01 00:00:00', '2026-03-03 00:00:00');

        $this->project('P1', $this->a, $this->u['clientA1'], $this->u['e1'], 'in_progress', '2026-03-02', '2026-03-06', 20);
        $this->project('P2', $this->a, $this->u['clientA2'], $this->u['e1'], 'completed', '2026-03-02', '2026-03-04', 6, '2026-03-05 08:00:00');
        $this->project('P3', $this->a, $this->u['clientA1'], $this->u['e2'], 'scheduled', '2026-03-02', '2026-03-03', 3);
        $this->project('P4', $this->b, $this->u['clientB'], $this->u['e4'], 'in_progress', '2026-03-02', '2026-03-07', 42);
        $this->project('P5', $this->a, $this->u['clientA1'], $this->u['e1'], 'cancelled', '2026-03-02', '2026-03-07', 6);

        $this->book('P1', $this->u['e1'], ['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'], 6);
        $this->book('P2', $this->u['e1'], ['2026-03-06'], 6);
        $this->book('P3', $this->u['e2'], ['2026-03-02'], 3);
        $this->book('P4', $this->u['e4'], ['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-07'], 7);
        $this->book('P5', $this->u['e1'], ['2026-03-07'], 6);

        $this->ticket('P1', $this->a, 'T1 todo', 'todo', 'normal', 5, '2026-03-02 09:00:00', '2026-03-02 09:00:00');
        $this->ticket('P1', $this->a, 'T2 done', 'done', 'high', 5, '2026-03-02 09:00:00', '2026-03-05 09:00:00');
        $this->ticket('P1', $this->a, 'T3 doing', 'in_progress', 'urgent', 5, '2026-03-03 09:00:00', '2026-03-03 09:00:00');
        $this->ticket('P2', $this->a, 'T4 done', 'done', 'low', 6, '2026-03-02 09:00:00', '2026-03-04 09:00:00');
        $this->ticket('P4', $this->b, 'T5 todo', 'todo', 'normal', 7, '2026-03-02 09:00:00', '2026-03-02 09:00:00');
    }

    private function report(string $as, string $audience, string $type, array $query = [])
    {
        return $this->actingAs($this->u[$as])->getJson("/api/{$audience}/reports/{$type}?".http_build_query(array_merge(self::RANGE, $query)));
    }

    // ---- access -----------------------------------------------------------------------------------------

    public function test_guests_get_401_and_wrong_roles_get_403(): void
    {
        $this->getJson('/api/admin/reports/overview')->assertUnauthorized();
        $this->getJson('/api/agency/reports/overview')->assertUnauthorized();

        foreach (['e1', 'ownerA', 'clientA1'] as $who) {
            $this->report($who, 'admin', 'overview')->assertForbidden();
        }
        foreach (['admin', 'e1', 'memberA'] as $who) {
            $this->report($who, 'agency', 'overview')->assertForbidden();
        }
        foreach (['admin', 'ownerA', 'clientA1'] as $who) {
            $this->assertContains(
                $this->report($who, $who === 'admin' ? 'admin' : 'agency', 'overview')->status(),
                [200]
            );
        }
    }

    public function test_agency_reports_exclude_admin_only_types_and_unknown_types_404(): void
    {
        $this->report('ownerA', 'agency', 'agencies')->assertNotFound();
        $this->report('ownerA', 'agency', 'assignments')->assertNotFound();
        $this->report('admin', 'admin', 'nonsense')->assertNotFound();
    }

    public function test_a_suspended_agency_gets_403_like_the_other_agency_routes(): void
    {
        $this->report('ownerC', 'agency', 'overview')->assertForbidden();
    }

    // ---- overview ---------------------------------------------------------------------------------------

    public function test_admin_overview_numbers(): void
    {
        $data = $this->report('admin', 'admin', 'overview')->assertOk()->json();

        $this->assertEquals(['active' => 2, 'suspended' => 1], $data['agencies']);
        $this->assertEquals(['scheduled' => 1, 'in_progress' => 2, 'completed' => 1, 'cancelled' => 1], $data['projects']);
        $this->assertEquals(2, $data['tickets']['todo']);
        $this->assertEquals(1, $data['tickets']['in_progress']);
        $this->assertEquals(2, $data['tickets']['done']);
        $this->assertEquals(1, $data['overdue_projects']);
        $this->assertEquals(25.0, $data['completion_rate']);
        $this->assertEquals(['active' => 4, 'inactive' => 1, 'held' => 3, 'available' => 1], $data['employees']);
        $this->assertEquals(1, $data['users']['agency_member']);
        $this->assertEquals(3, $data['users']['agency_client']);

        // series: one completed project in ISO week 10; 7 daily buckets
        $this->assertEquals(['2026-W10'], $data['series']['projects_completed_per_week']['labels']);
        $this->assertEquals([1], $data['series']['projects_completed_per_week']['values']);
        $this->assertCount(7, $data['series']['scheduled_hours_per_day']['labels']);
        // 24 + 6 + 3 (Alpha) + 42 (Beta); the cancelled project's hours are left out
        $this->assertEquals(75.0, array_sum($data['series']['scheduled_hours_per_day']['values']));

        $this->assertEquals('Project P3', $data['attention']['overdue_projects'][0]['title']);
        $this->assertEquals(2, $data['attention']['overdue_projects'][0]['days_late']);
        $this->assertEquals(1, $data['attention']['unassigned_employees']['count']);
        $this->assertEquals('Eva Four', $data['attention']['over_capacity'][0]['employee']);
        $this->assertEquals(42.0, $data['attention']['over_capacity'][0]['hours']);
    }

    public function test_agency_overviews_are_scoped(): void
    {
        $owner = $this->report('ownerA', 'agency', 'overview')->assertOk()->json();
        $this->assertNull($owner['agencies']);
        $this->assertEquals(['scheduled' => 1, 'in_progress' => 1, 'completed' => 1, 'cancelled' => 1], $owner['projects']);
        $this->assertEquals(1, $owner['overdue_projects']);
        $this->assertEquals(2, $owner['employees']['held']);
        $this->assertNull($owner['employees']['active']);
        $this->assertArrayNotHasKey('admin', $owner['users']);
        $this->assertEquals(1, $owner['users']['agency_owner']);

        $client = $this->report('clientA1', 'agency', 'overview')->assertOk()->json();
        $this->assertEquals(['scheduled' => 1, 'in_progress' => 1, 'completed' => 0, 'cancelled' => 1], $client['projects']);
        $this->assertNull($client['users']);
        $this->assertEquals(1, $client['employees']['held']);

        $other = $this->report('ownerB', 'agency', 'overview')->assertOk()->json();
        $this->assertEquals(['scheduled' => 0, 'in_progress' => 1, 'completed' => 0, 'cancelled' => 0], $other['projects']);
    }

    // ---- employees: utilization math ---------------------------------------------------------------------

    public function test_employee_utilization_uses_a_36_hour_week_and_labels_hours_as_scheduled(): void
    {
        $data = $this->report('admin', 'admin', 'employees', ['sort' => 'name'])->assertOk()->json();
        $rows = collect($data['rows'])->keyBy('name');

        $this->assertEquals(36.0, $rows['Eve One']['capacity_hours']);
        $this->assertEquals(30.0, $rows['Eve One']['scheduled_hours']); // 24 + 6; the cancelled project's 6 h is excluded
        $this->assertEquals(83.3, $rows['Eve One']['utilization']);
        $this->assertEquals('healthy', $rows['Eve One']['band']);
        $this->assertEquals(8.3, $rows['Eli Two']['utilization']);
        $this->assertEquals('low', $rows['Eli Two']['band']);
        $this->assertEquals(116.7, $rows['Eva Four']['utilization']);
        $this->assertEquals('high', $rows['Eva Four']['band']);
        $this->assertEquals(0.0, $rows['Ed Inactive']['utilization']);
        $this->assertFalse($rows['Ed Inactive']['is_active']);
        $this->assertEquals('Olive Alpha', $rows['Eve One']['holder']);
        $this->assertEquals('Alpha', $rows['Eve One']['agency']);
        $this->assertEquals(['todo' => 1, 'in_progress' => 1, 'done' => 2], array_intersect_key($rows['Eve One']['tickets'], array_flip(['todo', 'in_progress', 'done'])));
        $this->assertEquals(3, $rows['Eve One']['projects']); // P1, P2 and the cancelled P5 (all overlap the range)

        $this->assertEquals(5, $data['totals']['employees']);
        $this->assertEquals(180.0, $data['totals']['capacity_hours']);
        $this->assertEquals(75.0, $data['totals']['scheduled_hours']); // 30 + 3 + 42
        $this->assertEquals(41.7, $data['totals']['utilization']);
        $this->assertEquals(6, $data['totals']['working_days']);
    }

    public function test_non_working_dates_reduce_capacity(): void
    {
        config(['scheduling.non_working_dates' => ['2026-03-04']]);

        $data = $this->report('admin', 'admin', 'employees')->assertOk()->json();

        $this->assertEquals(30.0, $data['rows'][0]['capacity_hours']);
        $this->assertEquals(5, $data['totals']['working_days']);
    }

    public function test_employee_scoping_for_owner_and_client(): void
    {
        $owner = $this->report('ownerA', 'agency', 'employees', ['sort' => 'name'])->assertOk()->json();
        $this->assertEquals(['Eli Two', 'Eve One'], array_column($owner['rows'], 'name'));
        $this->assertNotContains('Eva Four', array_column($owner['rows'], 'name'));
        $this->assertEquals(30.0, collect($owner['rows'])->firstWhere('name', 'Eve One')['scheduled_hours']);

        // a client only counts hours on their own projects, and only sees themselves as holder
        $client = $this->report('clientA1', 'agency', 'employees', ['sort' => 'name'])->assertOk()->json();
        $rows = collect($client['rows'])->keyBy('name');
        $this->assertEquals(['Eli Two', 'Eve One'], array_column($client['rows'], 'name'));
        $this->assertEquals(24.0, $rows['Eve One']['scheduled_hours']); // P1 only, not P2 (another client's)
        $this->assertEquals('Carl One', $rows['Eli Two']['holder']);
        $this->assertNull($rows['Eve One']['holder']); // held by the owner: not visible to this client

        $this->assertEquals(['Eva Four'], array_column($this->report('ownerB', 'agency', 'employees')->json('rows'), 'name'));
    }

    public function test_employee_filter_and_admin_agency_filter(): void
    {
        $this->assertEquals(['Eli Two'], array_column($this->report('admin', 'admin', 'employees', ['employee_id' => $this->u['e2']->id])->json('rows'), 'name'));
        $byAgency = $this->report('admin', 'admin', 'employees', ['agency_id' => $this->b->id, 'sort' => 'name'])->json('rows');
        $this->assertEquals(['Eva Four'], array_column($byAgency, 'name'));

        // another agency's employee id yields nothing for an owner, never leaks
        $this->assertEquals([], $this->report('ownerA', 'agency', 'employees', ['employee_id' => $this->u['e4']->id])->json('rows'));
    }

    // ---- agencies / projects / tickets / assignments ------------------------------------------------------

    public function test_agency_summary(): void
    {
        $rows = collect($this->report('admin', 'admin', 'agencies', ['sort' => 'name'])->assertOk()->json('rows'))->keyBy('name');

        $this->assertEquals(
            ['owners' => 1, 'clients' => 2, 'employees_held' => 2, 'open_projects' => 2, 'completed_projects' => 1, 'cancelled_projects' => 1, 'scheduled_hours' => 33.0, 'completion_rate' => 33.3],
            array_intersect_key($rows['Alpha'], array_flip(['owners', 'clients', 'employees_held', 'open_projects', 'completed_projects', 'cancelled_projects', 'scheduled_hours', 'completion_rate']))
        );
        $this->assertEquals(42.0, $rows['Beta']['scheduled_hours']);
        $this->assertEquals('suspended', $rows['Gamma']['status']);
        $this->assertNull($rows['Gamma']['completion_rate']);

        $this->assertEquals(['Gamma'], array_column($this->report('admin', 'admin', 'agencies', ['status' => 'suspended'])->json('rows'), 'name'));
    }

    public function test_project_report_flags_and_scoping(): void
    {
        $rows = collect($this->report('admin', 'admin', 'projects', ['per_page' => 100])->assertOk()->json('rows'))->keyBy('title');

        $this->assertCount(5, $rows);
        $this->assertEquals('overdue', $rows['Project P3']['flag']);
        $this->assertEquals(2, $rows['Project P3']['days_late']);
        $this->assertEquals('at_risk', $rows['Project P1']['flag']);
        $this->assertEquals('at_risk', $rows['Project P4']['flag']);
        $this->assertNull($rows['Project P2']['flag']);
        $this->assertEquals(1, $rows['Project P2']['days_late']); // completed 03-05, due 03-04
        $this->assertEquals(15.0, $rows['Project P1']['ticketed_hours']);
        $this->assertEquals(20.0, $rows['Project P1']['estimated_hours']);

        $owner = $this->report('ownerA', 'agency', 'projects', ['per_page' => 100])->json();
        $this->assertEquals(4, $owner['pagination']['total']);
        $this->assertNotContains('Project P4', array_column($owner['rows'], 'title'));
        $this->assertEquals(1, $owner['totals']['overdue']);

        $client = $this->report('clientA1', 'agency', 'projects', ['sort' => 'title'])->json();
        $this->assertEquals(['Project P1', 'Project P3', 'Project P5'], array_column($client['rows'], 'title'));

        $byClient = $this->report('ownerA', 'agency', 'projects', ['client_id' => $this->u['clientA2']->id])->json('rows');
        $this->assertEquals(['Project P2'], array_column($byClient, 'title'));
        $this->assertEquals([], $this->report('ownerA', 'agency', 'projects', ['client_id' => $this->u['clientB']->id])->json('rows'));
        $this->assertEquals(['Project P3'], array_column($this->report('admin', 'admin', 'projects', ['status' => 'scheduled'])->json('rows'), 'title'));
    }

    public function test_ticket_report_matrix_throughput_and_average(): void
    {
        $data = $this->report('admin', 'admin', 'tickets')->assertOk()->json();

        $this->assertEquals(5, $data['pagination']['total']);
        $this->assertEquals(1, $data['summary']['matrix']['done']['high']);
        $this->assertEquals(1, $data['summary']['matrix']['done']['low']);
        $this->assertEquals(1, $data['summary']['matrix']['in_progress']['urgent']);
        $this->assertEquals(2, $data['summary']['matrix']['todo']['normal']);
        $this->assertEquals(['2026-W10'], $data['summary']['throughput']['labels']);
        $this->assertEquals([2], $data['summary']['throughput']['values']);
        $this->assertEquals(2.5, $data['summary']['avg_days_to_done']); // (3 + 2) / 2
        $this->assertEquals('ALPH-1', collect($data['rows'])->firstWhere('title', 'T1 todo')['key']);

        $this->assertEquals(2, $this->report('admin', 'admin', 'tickets', ['status' => 'done'])->json('pagination.total'));
        $this->assertEquals(4, $this->report('ownerA', 'agency', 'tickets')->json('pagination.total'));
        $this->assertEquals(1, $this->report('ownerB', 'agency', 'tickets')->json('pagination.total'));
        $this->assertEquals(3, $this->report('clientA1', 'agency', 'tickets')->json('pagination.total'));
    }

    public function test_assignment_report(): void
    {
        $data = $this->report('admin', 'admin', 'assignments', ['sort' => 'employee'])->assertOk()->json();

        $this->assertEquals(['assignments' => 4, 'active' => 3, 'ended' => 1], $data['totals']);
        $ended = collect($data['rows'])->firstWhere('status', 'ended');
        $this->assertEquals('Ed Inactive', $ended['employee']);
        $this->assertEquals(2.0, $ended['hold_days']);
        $this->assertEquals(1, $data['summary']['unassigned_count']);
        $this->assertEquals('=cmd|calc', $data['summary']['unassigned_employees'][0]['name']);
        $this->assertEquals(['Ed Inactive'], array_column($this->report('admin', 'admin', 'assignments', ['status' => 'ended'])->json('rows'), 'employee'));
    }

    // ---- validation, pagination, sorting -----------------------------------------------------------------

    public function test_filter_validation(): void
    {
        $bad = [
            ['from' => '2026-03-09', 'to' => '2026-03-02'],
            ['from' => '2025-01-01', 'to' => '2026-03-02'],
            ['status' => 'nope'],
            ['per_page' => 101],
            ['per_page' => 0],
            ['page' => 0],
            ['sort' => 'password'],
            ['direction' => 'sideways'],
            ['format' => 'pdf'],
            ['from' => 'yesterday'],
        ];
        foreach ($bad as $query) {
            $response = $this->actingAs($this->u['admin'])->getJson('/api/admin/reports/projects?'.http_build_query($query));
            $this->assertEquals(422, $response->status(), json_encode($query));
        }

        // a range of exactly one year (365 days apart) is fine
        $this->actingAs($this->u['admin'])->getJson('/api/admin/reports/projects?from=2025-03-05&to=2026-03-05')->assertOk();
        // sort whitelists are per report
        $this->report('admin', 'admin', 'projects', ['sort' => 'scheduled_hours'])->assertStatus(422);
        $this->report('admin', 'admin', 'employees', ['sort' => 'scheduled_hours'])->assertOk();
    }

    public function test_agency_id_and_client_id_filters_are_restricted_by_role(): void
    {
        $this->report('ownerA', 'agency', 'projects', ['agency_id' => $this->b->id])->assertStatus(422)->assertJsonValidationErrors('agency_id');
        $this->report('clientA1', 'agency', 'projects', ['agency_id' => $this->b->id])->assertStatus(422);
        $this->report('clientA1', 'agency', 'projects', ['client_id' => $this->u['clientA2']->id])->assertStatus(422)->assertJsonValidationErrors('client_id');
    }

    public function test_pagination_and_sorting(): void
    {
        $page1 = $this->report('admin', 'admin', 'employees', ['sort' => 'scheduled_hours', 'direction' => 'desc', 'per_page' => 2, 'page' => 1])->assertOk()->json();
        $page2 = $this->report('admin', 'admin', 'employees', ['sort' => 'scheduled_hours', 'direction' => 'desc', 'per_page' => 2, 'page' => 2])->json();

        $this->assertEquals(['Eva Four', 'Eve One'], array_column($page1['rows'], 'name'));
        $this->assertEquals(['Eli Two', 'Ed Inactive'], array_column($page2['rows'], 'name'));
        $this->assertEquals(['current_page' => 1, 'last_page' => 3, 'per_page' => 2, 'total' => 5], $page1['pagination']);
        $this->assertEquals('scheduled_hours:desc', $page1['sort']);

        $asc = $this->report('admin', 'admin', 'projects', ['sort' => 'estimated_hours', 'direction' => 'asc'])->json('rows');
        $this->assertEquals([3.0, 6.0, 6.0, 20.0, 42.0], array_column($asc, 'estimated_hours'));
    }

    // ---- CSV ----------------------------------------------------------------------------------------------

    public function test_csv_export_has_bom_header_row_and_neutralises_formulas(): void
    {
        $response = $this->report('admin', 'admin', 'employees', ['format' => 'csv', 'sort' => 'name']);
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('report-employees-2026-03-02-2026-03-08.csv', $response->headers->get('Content-Disposition'));
        $this->assertEquals('10000', $response->headers->get('X-Export-Row-Limit'));
        $this->assertEquals('false', $response->headers->get('X-Export-Truncated'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = preg_split('/\r?\n/', trim(substr($csv, 3)));
        $this->assertStringStartsWith('Employee,Active,Holder,Agency,Projects', $lines[0]);
        $this->assertCount(6, $lines); // header + 5 employees
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringNotContainsString(',=cmd|calc', $csv);
        $this->assertStringContainsString('83.3', $csv);
        $this->assertStringContainsString('Utilization % (scheduled, not worked)', $csv);
    }

    public function test_csv_is_scoped_and_filtered_like_the_json(): void
    {
        $csv = $this->report('ownerA', 'agency', 'projects', ['format' => 'csv'])->streamedContent();

        $this->assertStringContainsString('Project P1', $csv);
        $this->assertStringNotContainsString('Project P4', $csv);

        $filtered = $this->report('admin', 'admin', 'projects', ['format' => 'csv', 'status' => 'completed'])->streamedContent();
        $this->assertStringContainsString('Project P2', $filtered);
        $this->assertStringNotContainsString('Project P1', $filtered);

        $overview = $this->report('admin', 'admin', 'overview', ['format' => 'csv'])->streamedContent();
        $this->assertStringContainsString('Metric,Value', $overview);
        $this->assertStringContainsString('"Overdue projects",1', $overview);
    }

    public function test_csv_cell_escaping_unit(): void
    {
        foreach (['=1+1', '+1', '-1+2', '@SUM(A1)', "\tfoo", "\rfoo"] as $evil) {
            $this->assertStringStartsWith("'", \App\Services\Reports\CsvExport::cell($evil), $evil);
        }
        $this->assertEquals('-5', \App\Services\Reports\CsvExport::cell(-5)); // real numbers are not text
        $this->assertEquals('hello', \App\Services\Reports\CsvExport::cell('hello'));
        $this->assertEquals('', \App\Services\Reports\CsvExport::cell(null));
    }

    public function test_reports_use_a_fixed_number_of_queries(): void
    {
        foreach (['employees', 'projects', 'tickets', 'agencies', 'assignments', 'overview'] as $type) {
            DB::enableQueryLog();
            $this->report('admin', 'admin', $type, ['per_page' => 100])->assertOk();
            $queries = count(DB::getQueryLog());
            DB::flushQueryLog();
            DB::disableQueryLog();

            // session, user and the report's own grouped queries: independent of the number of rows (5 employees here)
            $this->assertLessThanOrEqual(20, $queries, "{$type} ran {$queries} queries");
        }
    }
}