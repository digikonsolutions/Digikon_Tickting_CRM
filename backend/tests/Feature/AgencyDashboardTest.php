<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeSkill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

class AgencyDashboardTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private Agency $agency;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWeek();
        $this->agency = $this->makeAgency('Northwind');
        $this->owner = $this->makeOwner($this->agency, 'Olivia Owner');
    }

    private function dash(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->getJson('/api/agency/dashboard')->assertOk();
    }

    public function test_only_agency_owners_and_clients_of_an_active_agency_can_open_it(): void
    {
        $this->getJson('/api/agency/dashboard')->assertUnauthorized();
        $this->postJson('/api/agency/requests', ['type' => 'renewal'])->assertUnauthorized();
        foreach ([Role::Admin, Role::Employee, Role::TeamLead, Role::AgencyMember] as $role) {
            $user = User::factory()->create(['role' => $role, 'agency_id' => $role === Role::Admin ? null : $this->agency->id]);
            $this->actingAs($user)->getJson('/api/agency/dashboard')->assertForbidden();
            $this->postJson('/api/agency/requests', ['type' => 'renewal'])->assertForbidden();
        }

        $this->agency->update(['status' => 'suspended']);
        $this->actingAs($this->owner)->getJson('/api/agency/dashboard')->assertForbidden();
        $this->actingAs($this->makeClient($this->agency))->getJson('/api/agency/dashboard')->assertForbidden();
    }

    public function test_owner_sees_a_no_contract_dashboard_with_real_usage_and_counts(): void
    {
        $client = $this->makeClient($this->agency, 'Carla Client');
        $this->makeClient($this->agency, 'Second Client');
        $ann = $this->makeEmployee('Ann');
        $ann->employeeProfile()->create(['headline' => 'Full-stack developer', 'department' => 'web']);
        EmployeeSkill::create(['user_id' => $ann->id, 'name' => 'Laravel', 'level' => 'expert']);
        $assignment = $this->hold($ann, $this->agency, $client);
        $this->book($assignment, 'in_progress', [[$this->monday, 6], [$this->monday->addDay(), 6], [$this->monday->addDays(2), 6]]);
        $this->book($assignment, 'cancelled', [[$this->monday->addDays(3), 6]]);

        $d = $this->dash($this->owner)->assertJsonPath('dashboard.role', 'agency_owner')->json('dashboard');

        $this->assertSame(['id' => $this->agency->id, 'name' => 'Northwind', 'status' => 'active'], $d['agency']);
        $this->assertNull($d['contract']);
        // Without a contract nothing is "allowed": limits are null, never zero, and never "reached".
        $this->assertSame(['used' => 1, 'allowed' => null, 'remaining' => null, 'reached' => false, 'nearly' => false], $d['limits']['employees']);
        $this->assertNull($d['limits']['hours']['allowed']);
        $this->assertNull($d['limits']['hours']['remaining']);
        $this->assertNull($d['limits']['hours']['projected_run_out']);
        $this->assertSame(1, $d['limits']['open_projects']['used']);
        $this->assertNull($d['limits']['open_projects']['allowed']);
        $this->assertSame(2, $d['limits']['client_accounts']['used']);
        $this->assertFalse($d['limits']['client_accounts']['reached']);
        // Held employee: public summary and this week's hours on this agency's projects (the cancelled 6 h is excluded).
        $employee = $d['employees'][0];
        $this->assertSame(['Ann', 'Full-stack developer', 'web', ['Laravel']], [$employee['name'], $employee['headline'], $employee['department'], $employee['top_skills']]);
        $this->assertSame([18.0, 36.0, 50], [(float) $employee['used_hours'], (float) $employee['budget_hours'], $employee['utilization_percent']]);
        $this->assertSame(18.0, (float) $d['weekly']['remaining_hours']);
        $this->assertSame(18.0, (float) $d['usage']['week_hours']);
        $this->assertSame($this->monday->toDateString(), $d['weekly']['week_start']);
        $this->assertCount(14, $d['usage']['last_14_days']);
        $this->assertCount(2, $d['recent_projects']);                                   // the cancelled one is still history
        $this->assertSame([], $d['attention']);
        $this->assertStringNotContainsString('"email"', json_encode($d['employees']));
    }

    public function test_burn_rate_is_the_booked_hours_of_the_last_14_days_per_day(): void
    {
        $assignment = $this->hold($this->makeEmployee('Ann'), $this->agency, $this->makeClient($this->agency));
        $this->book($assignment, 'in_progress', [[$this->today->subDays(2), 7], [$this->today->subDays(13), 7]]);
        $this->book($assignment, 'in_progress', [[$this->today->subDays(14), 50], [$this->today->addDay(), 50]]);   // outside the window

        $d = $this->dash($this->owner)->json('dashboard');

        $this->assertSame(1.0, (float) $d['limits']['hours']['burn_rate_per_day']);                    // 14 h over 14 days
        $this->assertSame(14.0, (float) array_sum(array_column($d['usage']['last_14_days'], 'hours')));
        $this->assertSame($this->today->subDays(13)->toDateString(), $d['usage']['last_14_days'][0]['date']);
        $this->assertSame($this->today->toDateString(), $d['usage']['last_14_days'][13]['date']);
    }

    public function test_attention_lists_overdue_and_due_soon_projects(): void
    {
        $assignment = $this->hold($this->makeEmployee('Ann'), $this->agency, $this->makeClient($this->agency));
        $this->book($assignment, 'in_progress', [[$this->monday, 1]], $this->today->subDays(2));
        $this->book($assignment, 'scheduled', [[$this->monday->addDay(), 1]], $this->today->addDays(2));
        $this->book($assignment, 'scheduled', [[$this->monday->addDays(2), 1]], $this->today->addDays(3));
        $this->book($assignment, 'completed', [[$this->monday->addDays(3), 1]], $this->today->subDays(9));

        $items = collect($this->dash($this->owner)->json('dashboard.attention'));

        $this->assertSame(['overdue_projects', 'due_soon_projects'], $items->pluck('type')->all());
        $this->assertSame(['danger', 'warning'], $items->pluck('severity')->all());
        $this->assertSame('1 project is overdue', $items[0]['label']);
        $this->assertSame('/agency/projects', $items[0]['link']);
    }

    public function test_a_client_gets_a_reduced_view_of_only_their_own_employees_and_projects(): void
    {
        $carla = $this->makeClient($this->agency, 'Carla Client');
        $dan = $this->makeClient($this->agency, 'Dan Client');
        $carlaAssignment = $this->hold($this->makeEmployee('Carla Employee'), $this->agency, $carla);
        $danAssignment = $this->hold($this->makeEmployee('Dan Employee'), $this->agency, $dan);
        $this->book($carlaAssignment, 'in_progress', [[$this->monday, 12]], null, 'Carla project');
        $this->book($danAssignment, 'in_progress', [[$this->monday, 24]], null, 'Dan project');

        $response = $this->dash($carla)->assertJsonPath('dashboard.role', 'agency_client');
        $d = $response->json('dashboard');

        $this->assertSame(['Carla Employee'], array_column($d['employees'], 'name'));
        $this->assertSame(['Carla project'], array_column($d['recent_projects'], 'title'));
        $this->assertSame(1, $d['limits']['employees']['used']);
        $this->assertSame([1, 3, false], [$d['limits']['open_projects']['used'], $d['limits']['open_projects']['allowed'], $d['limits']['open_projects']['reached']]);
        $this->assertSame(24.0, (float) $d['weekly']['remaining_hours']);                              // 36 - 12
        $this->assertSame(12.0, (float) $d['limits']['hours']['used']);                                 // her own month hours only
        $this->assertArrayNotHasKey('usage', $d);                                                        // no agency-wide usage
        $this->assertArrayNotHasKey('client_accounts', $d['limits']);
        $this->assertStringNotContainsString('Dan', $response->getContent());
        $this->assertStringNotContainsString('Olivia', $response->getContent());
        foreach (['price', 'payment_terms', 'discount', 'notes'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        // The owner sees both clients' employees and projects.
        $owner = $this->dash($this->owner)->json('dashboard');
        $this->assertEqualsCanonicalizing(['Carla Employee', 'Dan Employee'], array_column($owner['employees'], 'name'));
    }

    public function test_a_client_at_the_open_project_limit_is_told_so(): void
    {
        $carla = $this->makeClient($this->agency, 'Carla Client');
        $assignment = $this->hold($this->makeEmployee('Ann'), $this->agency, $carla);
        foreach (range(1, 3) as $n) {
            $this->book($assignment, 'scheduled', [[$this->monday->addDays($n), 1]]);
        }

        $limit = $this->dash($carla)->json('dashboard.limits.open_projects');

        $this->assertSame([3, 3, 0, true, true], [$limit['used'], $limit['allowed'], $limit['remaining'], $limit['reached'], $limit['nearly']]);
    }

    public function test_one_agency_never_sees_another_agencys_data(): void
    {
        $other = $this->makeAgency('Rival Ltd');
        $rivalOwner = $this->makeOwner($other, 'Rita Rival');
        $rivalClient = $this->makeClient($other, 'Rex Rival');
        $rivalEmployee = $this->makeEmployee('Rival Employee');
        $rivalEmployee->employeeProfile()->create(['headline' => 'Rival headline']);
        $rivalAssignment = $this->hold($rivalEmployee, $other, $rivalClient);
        $this->book($rivalAssignment, 'in_progress', [[$this->monday, 30], [$this->monday->subDays(2), 30]], $this->today->subDays(3), 'Rival secret project');

        $ours = $this->makeEmployee('Our Employee');
        $client = $this->makeClient($this->agency);
        $this->book($this->hold($ours, $this->agency, $client), 'in_progress', [[$this->monday, 6]], null, 'Our project');

        foreach ([$this->owner, $client] as $viewer) {
            $response = $this->dash($viewer);
            $body = $response->getContent();
            foreach (['Rival', 'Rita', 'Rex', 'secret project'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "{$viewer->role->value} saw '{$leak}'");
            }
            $d = $response->json('dashboard');
            $this->assertSame(['Our Employee'], array_column($d['employees'], 'name'));
            $this->assertSame(['Our project'], array_column($d['recent_projects'], 'title'));
            $this->assertSame([], $d['attention']);                                    // the rival's overdue project is not ours
            $this->assertSame(1, $d['limits']['open_projects']['used']);
        }
        // Hours are per agency: the rival's 60 booked hours do not touch ours.
        $this->assertSame(6.0, (float) $this->dash($this->owner)->json('dashboard.usage.week_hours'));
        // And the rival sees none of ours.
        $rival = $this->dash($rivalOwner)->getContent();
        foreach (['Our Employee', 'Our project', 'Olivia'] as $leak) {
            $this->assertStringNotContainsString($leak, $rival);
        }
    }

    public function test_dashboard_query_count_does_not_grow_with_employees_and_projects(): void
    {
        $measure = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->getJson('/api/agency/dashboard')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $client = $this->makeClient($this->agency);
        $this->book($this->hold($this->makeEmployee('First'), $this->agency, $client), 'in_progress', [[$this->monday, 6]]);
        $measure();
        $before = $measure();

        foreach (range(1, 12) as $n) {
            $employee = $this->makeEmployee('Employee '.$n);
            $employee->employeeProfile()->create(['headline' => 'Dev '.$n]);
            EmployeeSkill::create(['user_id' => $employee->id, 'name' => 'Skill '.$n, 'level' => 'expert']);
            $this->book($this->hold($employee, $this->agency, $client), 'in_progress', [[$this->monday->addDay(), 6]]);
        }

        $this->assertSame($before, $measure());
    }

    public function test_requests_notify_every_active_admin_and_only_them(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $secondAdmin = User::factory()->create(['role' => Role::Admin]);
        User::factory()->create(['role' => Role::Admin, 'is_active' => false]);
        $client = $this->makeClient($this->agency, 'Carla Client');

        $this->actingAs($client)->postJson('/api/agency/requests', ['type' => 'more_hours', 'message' => 'We need 20 more hours for launch.'])
            ->assertCreated()->assertJsonPath('notified', 2);

        foreach ([$admin, $secondAdmin] as $recipient) {
            $notification = $recipient->notifications()->latest()->firstOrFail()->data;
            $this->assertSame('Northwind requests more hours', $notification['title']);
            $this->assertStringContainsString('Carla Client from Northwind asks for more hours.', $notification['body']);
            $this->assertStringContainsString('We need 20 more hours for launch.', $notification['body']);
            $this->assertSame("/admin/agencies/{$this->agency->id}?tab=contract", $notification['url_path']);
        }
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertSame(0, $client->notifications()->count());
        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::query()->whereHasMorph('notifiable', [User::class], fn ($q) => $q->where('is_active', false))->count());
    }

    public function test_request_types_and_message_length_are_validated(): void
    {
        User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($this->owner);

        $this->postJson('/api/agency/requests', [])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/agency/requests', ['type' => 'free_money'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/agency/requests', ['type' => 'upgrade', 'message' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->postJson('/api/agency/requests', ['type' => 'renewal'])->assertCreated();
        $this->postJson('/api/agency/requests', ['type' => 'upgrade', 'message' => str_repeat('a', 1000)])->assertCreated();
    }

    public function test_requests_are_limited_to_five_a_minute_so_admins_are_not_flooded(): void
    {
        User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($this->owner);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/agency/requests', ['type' => 'more_hours'])->assertCreated();
        }
        $this->postJson('/api/agency/requests', ['type' => 'more_hours'])->assertStatus(429);
    }
}
