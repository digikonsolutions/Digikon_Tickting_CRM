<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmployeeSkill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

class AdminAgenciesTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWeek();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    public function test_agency_endpoints_are_admin_only(): void
    {
        $agency = $this->makeAgency('Northwind');
        $urls = ['/api/admin/agencies', "/api/admin/agencies/{$agency->id}"];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();   // guests first: actingAs below would stick to the test
        }
        foreach ($urls as $url) {
            foreach ([Role::AgencyOwner, Role::AgencyClient, Role::Employee, Role::TeamLead] as $role) {
                $this->actingAs(User::factory()->create(['role' => $role, 'agency_id' => $agency->id]))->getJson($url)->assertForbidden();
            }
        }
    }

    public function test_dashboard_agency_rows_carry_plan_seat_hour_and_renewal_fields_as_no_contract(): void
    {
        $agency = $this->makeAgency('Northwind');
        $this->makeOwner($agency, 'Olivia Owner');
        $assignment = $this->hold($this->makeEmployee('Ann'), $agency, $this->makeClient($agency));
        $this->book($assignment, 'in_progress', [[$this->monday, 6], [$this->monday->addDay(), 6]]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk();
        $row = $response->json('agency_rows.0');

        $this->assertSame('Olivia Owner', $row['owner_name']);
        $this->assertSame(12.0, (float) $row['hours_booked_this_week']);
        $this->assertSame(1, $row['employees_held']);
        foreach (['plan', 'seats_allowed', 'hours_included', 'renewal_date'] as $key) {
            $this->assertArrayHasKey($key, $row);
            $this->assertNull($row[$key], $key);
        }
        $this->assertFalse($row['has_contract']);
        $this->assertArrayHasKey('owner_email', $row);
        $response->assertJsonPath('contracts.available', false)->assertJsonPath('contracts.without_contract', 1)->assertJsonPath('contracts.expiring', []);
    }

    public function test_agency_list_is_searchable_and_paginated(): void
    {
        foreach (['Alpha Labs', 'Beta Labs', 'Gamma Studio'] as $name) {
            $this->makeAgency($name);
        }
        $this->actingAs($this->admin);

        $this->getJson('/api/admin/agencies?search=Labs')->assertOk()->assertJsonCount(2, 'agencies')->assertJsonPath('pagination.total', 2);
        $page = $this->getJson('/api/admin/agencies?per_page=2')->assertOk()->assertJsonCount(2, 'agencies')->assertJsonPath('pagination.last_page', 2);
        $this->assertArrayHasKey('hours_booked_this_week', $page->json('agencies.0'));
        $this->getJson('/api/admin/agencies?per_page=500')->assertUnprocessable();
    }

    public function test_agency_detail_returns_every_tab_and_empty_states_for_a_new_agency(): void
    {
        $empty = $this->makeAgency('Brand New');
        $this->actingAs($this->admin)->getJson("/api/admin/agencies/{$empty->id}")->assertOk()
            ->assertJsonPath('agency.name', 'Brand New')
            ->assertJsonPath('agency.owner', null)
            ->assertJsonPath('agency.contract', null)
            ->assertJsonPath('agency.employees', [])
            ->assertJsonPath('agency.projects', [])
            ->assertJsonPath('agency.counts.employees_held', 0)
            ->assertJsonPath('agency.usage.burn_rate_per_day', 0)
            ->assertJsonCount(14, 'agency.usage.last_14_days');
        $this->getJson('/api/admin/agencies/999999')->assertNotFound();
    }

    public function test_agency_detail_shows_owner_held_employees_and_projects(): void
    {
        $agency = $this->makeAgency('Northwind');
        $other = $this->makeAgency('Other');
        $owner = $this->makeOwner($agency, 'Olivia Owner');
        $client = $this->makeClient($agency);
        $ann = $this->makeEmployee('Ann');
        $ann->employeeProfile()->create(['headline' => 'Laravel developer', 'department' => 'web']);
        EmployeeSkill::create(['user_id' => $ann->id, 'name' => 'Laravel', 'level' => 'expert']);
        $assignment = $this->hold($ann, $agency, $client);
        $this->book($assignment, 'in_progress', [[$this->monday, 12]], null, 'Landing page');
        $this->book($this->hold($this->makeEmployee('Zed'), $other, $this->makeClient($other)), 'in_progress', [[$this->monday, 6]], null, 'Secret other project');

        $response = $this->actingAs($this->admin)->getJson("/api/admin/agencies/{$agency->id}")->assertOk();

        $this->assertSame($owner->email, $response->json('agency.owner.email'));
        $this->assertSame(['id', 'name', 'assignment_id', 'since', 'used_hours', 'budget_hours', 'utilization_percent', 'photo_url', 'headline', 'department', 'availability', 'top_skills'], array_keys($response->json('agency.employees.0')));
        $this->assertSame('Laravel developer', $response->json('agency.employees.0.headline'));
        $this->assertSame(['Laravel'], $response->json('agency.employees.0.top_skills'));
        $this->assertSame(12.0, (float) $response->json('agency.employees.0.used_hours'));
        $this->assertSame(['Landing page'], array_column($response->json('agency.projects'), 'title'));
        $this->assertSame(1, $response->json('agency.counts.open_projects'));
        $this->assertSame(1, $response->json('agency.counts.client_accounts'));
        $this->assertSame(12.0, (float) $response->json('agency.usage.week_hours'));
        $this->assertStringNotContainsString('Secret other project', $response->getContent());
        $this->assertStringNotContainsString('Zed', $response->getContent());
    }
}
