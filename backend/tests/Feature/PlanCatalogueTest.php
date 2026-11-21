<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\AgencyContract;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

class PlanCatalogueTest extends TestCase
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

    private function create(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/agency-owners', array_replace_recursive([
            'owner' => ['name' => 'Olivia Owner', 'email' => 'olivia@example.com'],
            'agency' => ['name' => 'Northwind Digital'],
        ], $overrides));
    }

    public function test_the_seeder_adds_the_three_pricing_page_plans_and_a_custom_one(): void
    {
        $this->seed(PlanSeeder::class);

        $plans = Plan::query()->orderBy('sort_order')->get();
        $this->assertSame(['Digikon Dedicated Builder', 'Agency AI Automation Partner', 'White Label AI Systems Pod', 'Custom plan'], $plans->pluck('name')->all());
        $this->assertSame([799.0, 1499.0, 3499.0, null], $plans->map(fn (Plan $p) => $p->price === null ? null : (float) $p->price)->all());
        $this->assertSame([1, 2, 3, null], $plans->pluck('employees')->all());
        $this->assertSame(['USD', 'USD', 'USD', 'USD'], $plans->pluck('currency')->all());
        $this->assertSame(['monthly', 'monthly', 'monthly', 'monthly'], $plans->pluck('billing_cycle')->all());
        $this->assertSame([false, false, false, true], $plans->pluck('is_custom')->all());
        $this->assertSame([10, 9, 11, 0], $plans->map(fn (Plan $p) => count($p->features))->all());
        $this->assertSame('1 dedicated GoHighLevel builder', $plans[0]->features[0]);
        $this->assertSame('1 dedicated automation builder + 1 delivery manager', $plans[1]->features[0]);
        $this->assertSame('2 dedicated automation builders + 1 delivery manager', $plans[2]->features[0]);
        $this->assertSame('14-day fix promise', $plans[0]->features[9]);
        $this->assertSame('Best for 1 GHL account or 5–10 sub-accounts.', $plans[0]->best_for);
        $this->assertSame(['Dedicated monthly support', 'Scale delivery', 'Expand capacity', 'Tailored'], $plans->pluck('tagline')->all());
        $this->assertSame([true, true, true, true], $plans->pluck('is_active')->all());
    }

    public function test_seeding_again_never_duplicates_or_overwrites_edited_plans(): void
    {
        $this->seed(PlanSeeder::class);
        $builder = Plan::where('name', 'Digikon Dedicated Builder')->firstOrFail();
        $builder->update(['price' => 849, 'hours_per_month' => 80, 'is_active' => false]);

        $this->seed(PlanSeeder::class);

        $this->assertSame(4, Plan::count());
        $builder->refresh();
        $this->assertSame([849.0, 80.0, false], [(float) $builder->price, (float) $builder->hours_per_month, $builder->is_active]);
    }

    public function test_the_plans_api_lists_them_in_order_with_what_is_included(): void
    {
        $this->seed(PlanSeeder::class);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/plans?active=1')->assertOk()->assertJsonCount(4, 'plans');

        $this->assertSame(['Digikon Dedicated Builder', 'Agency AI Automation Partner', 'White Label AI Systems Pod', 'Custom plan'], array_column($response->json('plans'), 'name'));
        $first = $response->json('plans.0');
        $this->assertSame(['Dedicated monthly support', 10, false, 799.0], [$first['tagline'], count($first['features']), $first['is_custom'], (float) $first['price']]);
        $this->assertTrue($response->json('plans.3.is_custom'));
        $this->assertNull($response->json('plans.3.price'));
    }

    public function test_plan_features_are_trimmed_limited_and_editable(): void
    {
        $this->actingAs($this->admin);

        $created = $this->postJson('/api/admin/plans', ['name' => 'Studio', 'tagline' => 'Boutique', 'best_for' => 'Small studios', 'features' => ['  One builder ', '', 'Weekly call'], 'sort_order' => 5])
            ->assertCreated()->assertJsonPath('plan.features', ['One builder', 'Weekly call'])->assertJsonPath('plan.sort_order', 5)->assertJsonPath('plan.is_custom', false);
        $id = $created->json('plan.id');
        $this->patchJson("/api/admin/plans/{$id}", ['features' => ['Only this'], 'is_custom' => true])->assertOk()->assertJsonPath('plan.features', ['Only this'])->assertJsonPath('plan.is_custom', true);
        $this->postJson('/api/admin/plans', ['name' => 'Too many', 'features' => array_fill(0, 31, 'x')])->assertUnprocessable()->assertJsonValidationErrors('features');
        $this->postJson('/api/admin/plans', ['name' => 'Long line', 'features' => [str_repeat('a', 161)]])->assertUnprocessable()->assertJsonValidationErrors('features.0');
        $this->postJson('/api/admin/plans', ['name' => 'Long heading', 'tagline' => str_repeat('a', 61)])->assertUnprocessable()->assertJsonValidationErrors('tagline');
    }

    public function test_a_contract_copies_the_plan_inclusions_and_later_plan_edits_do_not_change_them(): void
    {
        Notification::fake();
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('name', 'Agency AI Automation Partner')->firstOrFail();

        $response = $this->create(['contract' => ['plan_id' => $plan->id]])->assertCreated()
            ->assertJsonPath('contract.plan_name', 'Agency AI Automation Partner')->assertJsonPath('contract.price', 1499)
            ->assertJsonPath('contract.seats_allowed', 2)->assertJsonPath('contract.hours_included', null);

        $this->assertCount(9, $response->json('contract.features'));
        $contract = AgencyContract::firstOrFail();
        $this->assertSame($plan->features, $contract->features);

        $plan->update(['features' => ['Something new'], 'price' => 1, 'name' => 'Renamed']);
        $contract->refresh();
        $this->assertCount(9, $contract->features);
        $this->assertSame(['Agency AI Automation Partner', 1499.0], [$contract->plan_name, (float) $contract->price]);
    }

    public function test_the_admin_can_override_the_inclusions_for_one_agency(): void
    {
        Notification::fake();
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('name', 'Digikon Dedicated Builder')->firstOrFail();

        $this->create(['contract' => ['plan_id' => $plan->id, 'price' => 700, 'features' => ['  1 dedicated GoHighLevel builder ', 'Extra onboarding day', '']]])->assertCreated()
            ->assertJsonPath('contract.features', ['1 dedicated GoHighLevel builder', 'Extra onboarding day'])->assertJsonPath('contract.price', 700);

        $this->assertCount(10, $plan->fresh()->features);                                           // the plan itself is untouched
    }

    public function test_the_custom_plan_gives_a_contract_with_the_admins_own_numbers_and_inclusions(): void
    {
        Notification::fake();
        $this->seed(PlanSeeder::class);
        $custom = Plan::where('is_custom', true)->firstOrFail();

        $response = $this->create(['contract' => [
            'plan_id' => $custom->id, 'employees' => 4, 'hours_per_month' => 90, 'price' => 2500, 'billing_cycle' => 'quarterly',
            'features' => ['Two builders', 'Weekly reporting'],
        ]])->assertCreated();

        $this->assertSame(['Custom plan', 4, 90.0, 2500.0, 'quarterly', ['Two builders', 'Weekly reporting']], [
            $response->json('contract.plan_name'), $response->json('contract.seats_allowed'), (float) $response->json('contract.hours_included'),
            (float) $response->json('contract.price'), $response->json('contract.billing_cycle'), $response->json('contract.features'),
        ]);
        $this->assertSame($custom->id, AgencyContract::firstOrFail()->plan_id);
    }

    public function test_the_inclusions_show_on_the_admin_and_owner_views_but_never_leak_through_the_client_view(): void
    {
        Notification::fake();
        $this->seed(PlanSeeder::class);
        $plan = Plan::where('name', 'White Label AI Systems Pod')->firstOrFail();
        $this->create(['contract' => ['plan_id' => $plan->id, 'start_date' => $this->today->toDateString(), 'end_date' => $this->today->addMonth()->toDateString()]])->assertCreated();
        $agency = Agency::firstOrFail();
        $owner = User::where('email', 'olivia@example.com')->firstOrFail();
        $client = $this->makeClient($agency);

        $this->actingAs($this->admin)->getJson("/api/admin/agencies/{$agency->id}")->assertJsonCount(11, 'agency.contract.features')->assertJsonPath('agency.contract.plan_name', 'White Label AI Systems Pod');
        $this->actingAs($owner)->getJson('/api/agency/dashboard')->assertJsonCount(11, 'dashboard.contract.features')->assertJsonPath('dashboard.limits.employees.allowed', 3);
        $clientView = $this->actingAs($client)->getJson('/api/agency/dashboard')->assertOk();
        $this->assertSame(['plan_name', 'status', 'ends_on', 'renews_on', 'days_remaining'], array_keys($clientView->json('dashboard.contract')));
    }
}