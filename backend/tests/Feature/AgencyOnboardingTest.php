<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\AgencyContract;
use App\Models\AuditLog;
use App\Models\EmployeeAssignment;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Services\AgencyOnboardingService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

class AgencyOnboardingTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWeek();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::create([
            'name' => 'Growth', 'employees' => 3, 'hours_per_month' => 100, 'max_open_projects' => 5, 'max_client_accounts' => 4,
            'price' => 4800, 'currency' => 'USD', 'billing_cycle' => 'monthly', 'overage_rate' => 55, ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'mode' => 'create',
            'owner' => ['name' => 'Olivia Owner', 'email' => 'olivia@example.com'],
            'agency' => ['name' => 'Northwind Digital'],
        ], $overrides);
    }

    private function create(array $overrides = [], ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->postJson('/api/admin/agency-owners', $this->payload($overrides));
    }

    public function test_the_full_wizard_creates_agency_owner_profiles_contract_event_employees_and_sends_the_invite(): void
    {
        Notification::fake();
        $plan = $this->plan();
        $manager = User::factory()->create(['role' => Role::TeamLead]);
        $ann = $this->makeEmployee('Ann');
        $bob = $this->makeEmployee('Bob');

        $response = $this->create([
            'owner' => ['phone' => '+92 300 1234567', 'whatsapp' => '+92 300 7654321', 'job_title' => 'Founder', 'preferred_contact_method' => 'whatsapp', 'timezone' => 'Asia/Karachi'],
            'agency' => [
                'name' => 'dIGIKON sOLUTIONS', 'legal_name' => 'Digikon Solutions Pvt Ltd', 'brand_name' => 'Digikon', 'website' => 'https://digikon.example.com',
                'industry' => 'Marketing', 'country' => 'Pakistan', 'city' => 'Sialkot', 'address' => '1 Mall Road', 'company_size' => '11-50',
                'tax_number' => 'NTN-1234567-8', 'billing_email' => 'billing@digikon.example.com', 'billing_contact_name' => 'Bella Billing',
                'account_manager_id' => $manager->id, 'internal_notes' => 'Referred by Sam.',
            ],
            'contract' => [
                'plan_id' => $plan->id, 'hours_per_month' => 120, 'price' => 4500, 'start_date' => $this->today->toDateString(),
                'end_date' => $this->today->addYear()->toDateString(), 'auto_renew' => true, 'notice_period_days' => 30, 'status' => 'active',
                'payment_terms_days' => 15, 'discount_percent' => 10,
            ],
            'setup' => ['employee_ids' => [$ann->id, $bob->id], 'brand_color' => '#0F766E', 'send_invite' => true, 'welcome_message' => 'Welcome aboard! Your account manager is Sam.'],
        ])->assertCreated();

        $agency = Agency::query()->where('name', 'Digikon Solutions')->firstOrFail();   // broken casing was repaired
        $owner = User::where('email', 'olivia@example.com')->firstOrFail();
        $this->assertSame([Role::AgencyOwner, true, $agency->id], [$owner->role, $owner->is_active, $owner->agency_id]);
        $response->assertJsonPath('agency.status', 'active')->assertJsonPath('agency.name', 'Digikon Solutions')
            ->assertJsonPath('owner.email', 'olivia@example.com')->assertJsonPath('owner.is_active', true)
            ->assertJsonPath('contract.plan_name', 'Growth')->assertJsonPath('contract.seats_allowed', 3)
            ->assertJsonPath('contract.hours_included', 120)->assertJsonPath('warnings', []);

        $this->assertSame(['+92 300 1234567', 'Founder', 'whatsapp', 'Asia/Karachi'], [$owner->ownerProfile->phone, $owner->ownerProfile->job_title, $owner->ownerProfile->preferred_contact_method, $owner->ownerProfile->timezone]);
        $profile = $agency->profile;
        $this->assertSame(['Digikon Solutions Pvt Ltd', 'Sialkot', $manager->id, 'billing@digikon.example.com'], [$profile->legal_name, $profile->city, $profile->account_manager_id, $profile->billing_email]);
        $this->assertSame('NTN-1234567-8', $profile->tax_number);
        $this->assertStringNotContainsString('NTN-1234567-8', (string) DB::table('agency_profiles')->value('tax_number'));   // encrypted at rest
        $this->assertSame('#0F766E', $agency->branding()->first()->primary_color);

        $contract = $agency->contracts()->firstOrFail();
        $this->assertSame([$plan->id, 'Growth', 3, 120.0, 5, 4, 4500.0, 'USD', 'monthly', 55.0], [$contract->plan_id, $contract->plan_name, $contract->employees, (float) $contract->hours_per_month, $contract->max_open_projects, $contract->max_client_accounts, (float) $contract->price, $contract->currency, $contract->billing_cycle, (float) $contract->overage_rate]);
        $this->assertSame([true, 30, 15, 10.0, $this->admin->id], [$contract->auto_renew, $contract->notice_period_days, $contract->payment_terms_days, (float) $contract->discount_percent, $contract->created_by]);
        $this->assertDatabaseHas('contract_events', ['agency_contract_id' => $contract->id, 'type' => 'created', 'user_id' => $this->admin->id]);

        $this->assertSame(2, EmployeeAssignment::where(['agency_id' => $agency->id, 'holder_id' => $owner->id, 'status' => 'active'])->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'created_agency', 'subject_id' => $agency->id, 'user_id' => $this->admin->id]);
        Notification::assertSentTo($owner, SetPasswordNotification::class, fn (SetPasswordNotification $n): bool => $n->invite && $n->welcomeLine === 'Welcome aboard! Your account manager is Sam.');
    }

    public function test_the_invitation_email_carries_the_welcome_line(): void
    {
        $owner = User::factory()->create(['role' => Role::AgencyOwner]);
        $mail = (new SetPasswordNotification('token', true, 'Welcome aboard!'))->toMail($owner);

        $this->assertContains('Welcome aboard!', $mail->introLines);
        $this->assertNotContains('Welcome aboard!', (new SetPasswordNotification('token', false, 'Welcome aboard!'))->toMail($owner)->introLines);
    }

    public function test_a_minimal_create_with_only_the_three_required_fields_works(): void
    {
        Notification::fake();

        $response = $this->create()->assertCreated()
            ->assertJsonPath('agency.status', 'active')->assertJsonPath('contract', null);

        $owner = User::where('email', 'olivia@example.com')->firstOrFail();
        $this->assertTrue($owner->is_active);
        Notification::assertSentTo($owner, SetPasswordNotification::class, fn (SetPasswordNotification $n): bool => $n->welcomeLine === null);
        $warnings = implode(' ', $response->json('warnings'));
        $this->assertStringContainsString('No contract added', $warnings);
        $this->assertStringContainsString('No employees assigned', $warnings);
        $this->assertDatabaseCount('agency_contracts', 0);
    }

    public function test_required_fields_are_enforced_and_the_email_must_be_new(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        User::factory()->create(['email' => 'taken@example.com']);
        $this->actingAs($this->admin);

        $this->postJson('/api/admin/agency-owners', [])->assertUnprocessable()->assertJsonValidationErrors(['owner', 'agency']);
        $this->postJson('/api/admin/agency-owners', ['owner' => ['name' => '', 'email' => ''], 'agency' => ['name' => '']])
            ->assertUnprocessable()->assertJsonValidationErrors(['owner.name', 'owner.email', 'agency.name']);
        $this->create(['owner' => ['email' => '  TAKEN@example.com ']])->assertUnprocessable()->assertJsonValidationErrors('owner.email');
        $this->create(['mode' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->create(['owner' => ['preferred_contact_method' => 'pigeon', 'phone' => 'abc']])->assertUnprocessable()->assertJsonValidationErrors(['owner.preferred_contact_method', 'owner.phone']);
        $this->assertDatabaseCount('agencies', 0);
    }

    public function test_the_owner_email_is_normalised(): void
    {
        Notification::fake();
        $this->create(['owner' => ['email' => '  Olivia@Example.COM ']])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'olivia@example.com', 'role' => 'agency_owner']);
    }

    public function test_contract_rules_dates_trial_discount_currency_and_account_manager(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->actingAs($this->admin);
        $start = $this->today->toDateString();

        $this->create(['contract' => ['start_date' => $start, 'end_date' => $start]])->assertUnprocessable()->assertJsonValidationErrors('contract.end_date');
        $this->create(['contract' => ['start_date' => $start, 'end_date' => $this->today->subDay()->toDateString()]])->assertUnprocessable()->assertJsonValidationErrors('contract.end_date');
        $this->create(['contract' => ['status' => 'trial', 'price' => 0]])->assertUnprocessable()->assertJsonValidationErrors('contract.trial_end_date');
        $this->create(['contract' => ['status' => 'trial', 'start_date' => $start, 'trial_end_date' => $this->today->subDay()->toDateString()]])->assertUnprocessable()->assertJsonValidationErrors('contract.trial_end_date');
        $this->create(['contract' => ['status' => 'paused', 'price' => 1]])->assertUnprocessable()->assertJsonValidationErrors('contract.status');
        $this->create(['contract' => ['discount_percent' => 101]])->assertUnprocessable()->assertJsonValidationErrors('contract.discount_percent');
        $this->create(['contract' => ['discount_percent' => -1]])->assertUnprocessable()->assertJsonValidationErrors('contract.discount_percent');
        $this->create(['contract' => ['currency' => 'DOLLARS', 'price' => 1]])->assertUnprocessable()->assertJsonValidationErrors('contract.currency');
        $this->create(['contract' => ['billing_cycle' => 'weekly', 'price' => 1]])->assertUnprocessable()->assertJsonValidationErrors('contract.billing_cycle');
        $this->create(['contract' => ['plan_id' => 9999]])->assertUnprocessable()->assertJsonValidationErrors('contract.plan_id');
        $this->create(['contract' => ['plan_id' => $this->plan(['name' => 'Retired', 'is_active' => false])->id]])->assertUnprocessable()->assertJsonValidationErrors('contract.plan_id');
        $employee = $this->makeEmployee('Ann');
        $this->create(['agency' => ['account_manager_id' => $employee->id]])->assertUnprocessable()->assertJsonValidationErrors('agency.account_manager_id');
        $this->create(['setup' => ['brand_color' => '#FFFF99']])->assertUnprocessable()->assertJsonValidationErrors('setup.brand_color');
        $this->create(['setup' => ['brand_color' => 'teal']])->assertUnprocessable()->assertJsonValidationErrors('setup.brand_color');
        $this->assertDatabaseCount('agencies', 0);
    }

    public function test_a_trial_contract_is_stored_with_its_trial_end_date(): void
    {
        Notification::fake();
        $this->create(['contract' => ['status' => 'trial', 'price' => 0, 'hours_per_month' => 10, 'trial_end_date' => $this->today->addDays(14)->toDateString()]])
            ->assertCreated()->assertJsonPath('contract.status', 'trial')->assertJsonPath('contract.trial_ends_on', $this->today->addDays(14)->toDateString());
    }

    public function test_a_draft_sends_nothing_and_the_owner_cannot_sign_in(): void
    {
        Notification::fake();

        $response = $this->create(['mode' => 'draft', 'setup' => ['send_invite' => true]])->assertCreated()
            ->assertJsonPath('agency.status', 'draft')->assertJsonPath('owner.is_active', false);

        Notification::assertNothingSent();
        $this->assertStringContainsString('Saved as a draft', implode(' ', $response->json('warnings')));
        $owner = User::where('email', 'olivia@example.com')->firstOrFail();
        $owner->forceFill(['password' => 'Str0ng!Passw0rd-xyz'])->save();
        $this->assertFalse($owner->is_active);
        $this->postJson('/api/auth/login', ['email' => 'olivia@example.com', 'password' => 'Str0ng!Passw0rd-xyz'])->assertStatus(422);
        $this->assertGuest();
        $this->assertSame('draft', Agency::firstOrFail()->status);
    }

    public function test_activating_a_draft_switches_it_on_and_sends_the_invite_with_the_saved_welcome_line(): void
    {
        Notification::fake();
        $this->create(['mode' => 'draft', 'setup' => ['welcome_message' => 'Glad to have you!']])->assertCreated();
        $agency = Agency::firstOrFail();
        $owner = User::where('email', 'olivia@example.com')->firstOrFail();
        Notification::assertNothingSent();

        $this->actingAs($this->admin)->postJson("/api/admin/agencies/{$agency->id}/activate")->assertOk()
            ->assertJsonPath('agency.status', 'active')->assertJsonPath('owner.is_active', true);

        $this->assertTrue($owner->fresh()->is_active);
        $this->assertSame('active', $agency->fresh()->status);
        Notification::assertSentTo($owner, SetPasswordNotification::class, fn (SetPasswordNotification $n): bool => $n->invite && $n->welcomeLine === 'Glad to have you!');
        $this->assertDatabaseHas('audit_logs', ['action' => 'activated_agency', 'subject_id' => $agency->id]);
        // Activating again, or activating an agency that was never a draft, is refused.
        $this->postJson("/api/admin/agencies/{$agency->id}/activate")->assertUnprocessable()->assertJsonValidationErrors('agency');
    }

    public function test_activation_is_refused_without_an_owner_and_rolls_back_if_the_invite_fails(): void
    {
        $orphan = Agency::create(['name' => 'Orphan', 'status' => 'draft']);
        $this->actingAs($this->admin)->postJson("/api/admin/agencies/{$orphan->id}/activate")->assertUnprocessable()->assertJsonValidationErrors('owner');
        $this->assertSame('draft', $orphan->fresh()->status);

        Notification::fake();
        $this->create(['mode' => 'draft'])->assertCreated();
        $agency = Agency::where('name', 'Northwind Digital')->firstOrFail();
        $this->mock(Dispatcher::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('Invitation delivery failed.'));

        $this->postJson("/api/admin/agencies/{$agency->id}/activate")->assertInternalServerError();

        $this->assertSame('draft', $agency->fresh()->status);
        $this->assertFalse(User::where('email', 'olivia@example.com')->firstOrFail()->is_active);
    }

    public function test_a_draft_agency_cannot_be_flipped_by_the_plain_status_endpoint(): void
    {
        Notification::fake();
        $this->create(['mode' => 'draft'])->assertCreated();
        $agency = Agency::firstOrFail();

        $this->actingAs($this->admin)->patchJson("/api/admin/agencies/{$agency->id}", ['status' => 'active'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('draft', $agency->fresh()->status);
    }

    public function test_a_failed_invitation_rolls_back_every_row(): void
    {
        $plan = $this->plan();
        $ann = $this->makeEmployee('Ann');
        $this->mock(Dispatcher::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('Invitation delivery failed.'));

        $this->create([
            'contract' => ['plan_id' => $plan->id],
            'setup' => ['employee_ids' => [$ann->id], 'brand_color' => '#0F766E'],
        ])->assertInternalServerError();

        $this->assertDatabaseMissing('users', ['email' => 'olivia@example.com']);
        foreach (['agencies', 'agency_profiles', 'owner_profiles', 'agency_contracts', 'contract_events', 'agency_branding', 'employee_assignments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'created_agency']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'olivia@example.com']);
        $this->assertNull(EmployeeAssignment::query()->where('employee_id', $ann->id)->first());
    }

    public function test_the_contract_is_a_snapshot_that_later_plan_edits_never_change(): void
    {
        Notification::fake();
        $plan = $this->plan();
        $this->create(['contract' => ['plan_id' => $plan->id, 'employees' => 2]])->assertCreated();   // an override beats the plan
        $contract = AgencyContract::firstOrFail();
        $this->assertSame([2, 100.0, 4800.0], [$contract->employees, (float) $contract->hours_per_month, (float) $contract->price]);

        $this->patchJson("/api/admin/plans/{$plan->id}", ['employees' => 99, 'hours_per_month' => 5, 'price' => 1, 'name' => 'Growth v2'])->assertOk();

        $fresh = $contract->fresh();
        $this->assertSame([2, 100.0, 4800.0, 'Growth'], [$fresh->employees, (float) $fresh->hours_per_month, (float) $fresh->price, $fresh->plan_name]);
        $this->assertSame(99, $plan->fresh()->employees);
    }

    public function test_a_contract_without_a_plan_is_custom_and_keeps_its_own_numbers(): void
    {
        Notification::fake();
        $this->create(['contract' => ['employees' => 4, 'hours_per_month' => 60, 'price' => 900, 'currency' => 'eur']])->assertCreated()
            ->assertJsonPath('contract.plan_name', 'Custom')->assertJsonPath('contract.currency', 'EUR')->assertJsonPath('contract.seats_allowed', 4);
        $this->assertNull(AgencyContract::firstOrFail()->plan_id);
    }

    public function test_an_agency_can_have_only_one_trial_or_active_contract(): void
    {
        $agency = $this->makeAgency('Northwind');
        $make = fn (string $status) => AgencyContract::create(['agency_id' => $agency->id, 'status' => $status, 'start_date' => $this->today->toDateString(), 'plan_name' => 'X']);

        $make('active');
        $make('expired');
        $make('cancelled');                                     // any number of finished contracts is fine
        $this->expectException(UniqueConstraintViolationException::class);
        $make('trial');
    }

    public function test_finishing_a_live_contract_makes_room_for_a_new_one(): void
    {
        $agency = $this->makeAgency('Northwind');
        $first = AgencyContract::create(['agency_id' => $agency->id, 'status' => 'active', 'start_date' => $this->today->toDateString()]);
        $first->update(['status' => 'expired']);

        AgencyContract::create(['agency_id' => $agency->id, 'status' => 'trial', 'start_date' => $this->today->toDateString()]);

        $this->assertSame(1, AgencyContract::whereNotNull('live_agency_id')->count());
    }

    public function test_seats_cannot_be_exceeded_by_the_chosen_employees(): void
    {
        $plan = $this->plan(['employees' => 2]);
        [$a, $b, $c] = [$this->makeEmployee('A'), $this->makeEmployee('B'), $this->makeEmployee('C')];

        $this->create(['contract' => ['plan_id' => $plan->id], 'setup' => ['employee_ids' => [$a->id, $b->id, $c->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('setup.employee_ids');
        $this->create(['contract' => ['employees' => 1], 'setup' => ['employee_ids' => [$a->id, $b->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('setup.employee_ids');
        $this->assertDatabaseCount('agencies', 0);

        Notification::fake();
        $this->create(['contract' => ['plan_id' => $plan->id], 'setup' => ['employee_ids' => [$a->id, $b->id]]])->assertCreated();
        // Without a contract there is no seat limit to exceed.
        $this->create(['owner' => ['email' => 'two@example.com'], 'agency' => ['name' => 'Second'], 'setup' => ['employee_ids' => [$c->id]]])->assertCreated();
    }

    public function test_held_inactive_and_unknown_employees_are_rejected(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $other = $this->makeAgency('Other');
        $held = $this->makeEmployee('Held');
        $this->hold($held, $other, $this->makeClient($other));
        $inactive = $this->makeEmployee('Inactive', ['is_active' => false]);
        $notAnEmployee = $this->makeClient($other, 'A Client');
        $this->actingAs($this->admin);

        $this->create(['setup' => ['employee_ids' => [$held->id]]])->assertUnprocessable()->assertJsonValidationErrors('setup.employee_ids');
        $this->create(['setup' => ['employee_ids' => [$inactive->id]]])->assertUnprocessable()->assertJsonValidationErrors('setup.employee_ids.0');
        $this->create(['setup' => ['employee_ids' => [$notAnEmployee->id]]])->assertUnprocessable()->assertJsonValidationErrors('setup.employee_ids.0');
        $this->create(['setup' => ['employee_ids' => [999999]]])->assertUnprocessable()->assertJsonValidationErrors('setup.employee_ids.0');
        $this->assertDatabaseCount('agencies', 1);                                                       // only the seeded one
    }

    public function test_employees_are_checked_again_inside_the_transaction_so_a_race_cannot_double_book(): void
    {
        $service = app(AgencyOnboardingService::class);
        $ann = $this->makeEmployee('Ann');
        $other = $this->makeAgency('Other');
        $data = [
            'mode' => 'create',
            'owner' => ['name' => 'Olivia Owner', 'email' => 'olivia@example.com'],
            'agency' => ['name' => 'Northwind Digital'],
            'contract' => ['employees' => 1],
            'setup' => ['employee_ids' => [$ann->id], 'send_invite' => false],
        ];

        // Another admin takes Ann after the request was validated but before this transaction runs.
        $this->hold($ann, $other, $this->makeClient($other));
        try {
            $service->create($data, $this->admin);
            $this->fail('A held employee must be refused inside the transaction.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('setup.employee_ids', $exception->errors());
        }
        $this->assertDatabaseMissing('users', ['email' => 'olivia@example.com']);
        $this->assertDatabaseMissing('agencies', ['name' => 'Northwind Digital']);

        // The seat count is re-checked there too.
        $free = [$this->makeEmployee('B'), $this->makeEmployee('C')];
        try {
            $service->create([...$data, 'setup' => ['employee_ids' => array_map(fn (User $u): int => $u->id, $free), 'send_invite' => false]], $this->admin);
            $this->fail('Too many employees for the seats must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('allows 1 employee', $exception->errors()['setup.employee_ids'][0]);
        }
        $this->assertDatabaseCount('employee_assignments', 1);
    }

    public function test_send_invite_false_creates_an_active_owner_without_an_email(): void
    {
        Notification::fake();

        $response = $this->create(['setup' => ['send_invite' => false]])->assertCreated()->assertJsonPath('owner.is_active', true);

        Notification::assertNothingSent();
        $this->assertStringContainsString('No invitation was sent', implode(' ', $response->json('warnings')));
    }

    public function test_the_logo_is_uploaded_to_the_existing_branding_pipeline_after_the_agency_exists(): void
    {
        Notification::fake();
        $response = $this->actingAs($this->admin)->post('/api/admin/agency-owners', [
            'owner' => ['name' => 'Olivia Owner', 'email' => 'olivia@example.com'],
            'agency' => ['name' => 'Northwind Digital'],
            'setup' => ['logo' => UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::PNG))],
        ], ['Accept' => 'application/json'])->assertCreated();

        $agency = Agency::firstOrFail();
        $this->assertStringStartsWith('https://res.cloudinary.test/', (string) $agency->branding()->first()->logo_url);
        $this->assertStringNotContainsString('logo could not', implode(' ', $response->json('warnings')));

        // The same upload as a separate step (what the wizard does), and a bad file is refused.
        $this->post("/api/admin/agencies/{$agency->id}/logo", ['file' => UploadedFile::fake()->createWithContent('logo2.png', base64_decode(self::PNG))], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonStructure(['logo_url']);
        $this->post("/api/admin/agencies/{$agency->id}/logo", ['file' => UploadedFile::fake()->createWithContent('x.png', '<html/>')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_only_admins_can_use_the_wizard_and_its_endpoints(): void
    {
        $agency = $this->makeAgency('Northwind');
        $planId = $this->plan()->id;
        $contract = AgencyContract::create(['agency_id' => $agency->id, 'status' => 'active', 'start_date' => $this->today->toDateString()]);
        $documentId = \App\Models\ContractDocument::create(['agency_contract_id' => $contract->id, 'agency_id' => $agency->id, 'original_name' => 'a.pdf', 'path' => 'contracts/x.pdf', 'disk' => 'local', 'mime' => 'application/pdf', 'size' => 1])->id;
        $calls = [
            ['postJson', '/api/admin/agency-owners'],
            ['postJson', "/api/admin/agencies/{$agency->id}/activate"],
            ['postJson', "/api/admin/agencies/{$agency->id}/contract-documents"],
            ['getJson', "/api/admin/agencies/{$agency->id}/contract-documents/{$documentId}"],
            ['postJson', "/api/admin/agencies/{$agency->id}/logo"],
            ['getJson', '/api/admin/plans'],
            ['postJson', '/api/admin/plans'],
            ['patchJson', "/api/admin/plans/{$planId}"],
        ];
        foreach ($calls as [$method, $url]) {
            $this->{$method}($url)->assertUnauthorized();
        }
        $roles = [
            User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]),
            User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]),
            User::factory()->create(['role' => Role::Employee]),
            User::factory()->create(['role' => Role::TeamLead]),
        ];
        foreach ($roles as $user) {
            foreach ($calls as [$method, $url]) {
                $this->actingAs($user)->{$method}($url)->assertForbidden();
            }
        }
    }

    public function test_the_existing_bare_owner_endpoint_still_works(): void
    {
        Notification::fake();
        $this->actingAs($this->admin)->postJson('/api/auth/users', ['name' => 'Legacy Owner', 'email' => 'legacy@example.com', 'role' => 'agency_owner', 'agency_name' => 'Legacy Agency'])
            ->assertCreated()->assertJsonPath('user.agency_name', 'Legacy Agency');

        $this->assertSame('active', Agency::where('name', 'Legacy Agency')->value('status'));
        $this->assertDatabaseCount('agency_contracts', 0);
    }

    public function test_contract_documents_need_a_contract_and_are_private_validated_and_authorised(): void
    {
        Notification::fake();
        $this->create(['contract' => ['plan_id' => $this->plan()->id]])->assertCreated();
        $agency = Agency::firstOrFail();
        $bare = $this->makeAgency('No Contract Ltd');
        $pdf = fn (string $name = 'Signed contract.pdf') => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $post = fn (Agency $target, UploadedFile $file) => $this->actingAs($this->admin)->post("/api/admin/agencies/{$target->id}/contract-documents", ['file' => $file], ['Accept' => 'application/json']);

        $post($bare, $pdf())->assertUnprocessable()->assertJsonValidationErrors('file');

        $response = $post($agency, $pdf())->assertCreated()->assertJsonPath('document.name', 'Signed contract.pdf');
        $document = \App\Models\ContractDocument::firstOrFail();
        $this->assertMatchesRegularExpression('#^contracts/'.$agency->id.'/[0-9a-f-]{36}\.pdf$#', $document->path);
        Storage::disk('local')->assertExists($document->path);
        Storage::disk('public')->assertMissing($document->path);
        $this->assertStringNotContainsString($document->path, $response->getContent());
        $this->assertStringNotContainsString('"path"', $response->getContent());
        $this->assertDatabaseHas('contract_events', ['type' => 'document_uploaded']);

        // Wrong content, wrong type, empty and oversize files are refused.
        $post($agency, UploadedFile::fake()->createWithContent('fake.pdf', base64_decode(self::PNG)))->assertUnprocessable();
        $post($agency, UploadedFile::fake()->createWithContent('notes.txt', 'hello'))->assertUnprocessable();
        $post($agency, UploadedFile::fake()->createWithContent('empty.pdf', ''))->assertUnprocessable();
        $big = $post($agency, UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf'))->assertUnprocessable();
        $this->assertStringContainsString('larger than 10 MB', $big->json('errors.file.0'));
        $post($agency, UploadedFile::fake()->createWithContent('scan.png', base64_decode(self::PNG)))->assertCreated();
        $this->assertDatabaseCount('contract_documents', 2);

        // Download: admins only, streamed, audited; another agency's id is a 404.
        $url = "/api/admin/agencies/{$agency->id}/contract-documents/{$document->id}";
        $download = $this->actingAs($this->admin)->get($url)->assertOk();
        $this->assertStringContainsString('%PDF-1.4', $download->streamedContent());
        $download->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('audit_logs', ['action' => 'downloaded_document', 'subject_id' => $document->id]);
        $this->get("/api/admin/agencies/{$bare->id}/contract-documents/{$document->id}")->assertNotFound();
        $owner = User::where('email', 'olivia@example.com')->firstOrFail();
        $this->actingAs($owner)->get($url)->assertForbidden();
        $this->assertSame(1, AuditLog::where('action', 'downloaded_document')->count());
    }

    public function test_an_owner_downloads_their_own_contract_document_and_nobody_elses(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $client = $this->makeClient($agency);
        $rival = $this->makeOwner($this->makeAgency('Rival'), 'Rita');
        $contract = AgencyContract::create(['agency_id' => $agency->id, 'status' => 'active', 'start_date' => $this->today->toDateString(), 'plan_name' => 'Growth']);
        $this->actingAs($owner)->getJson('/api/agency/dashboard')->assertOk()->assertJsonPath('dashboard.contract.document_available', false);
        $this->get('/api/agency/contract/document')->assertNotFound();

        Storage::disk('local')->put('contracts/1/x.pdf', '%PDF-1.4 mine');
        \App\Models\ContractDocument::create(['agency_contract_id' => $contract->id, 'agency_id' => $agency->id, 'original_name' => 'mine.pdf', 'path' => 'contracts/1/x.pdf', 'disk' => 'local', 'mime' => 'application/pdf', 'size' => 13]);

        $this->get('/api/agency/contract/document')->assertOk();
        $this->getJson('/api/agency/dashboard')->assertJsonPath('dashboard.contract.document_available', true);
        $this->actingAs($client)->get('/api/agency/contract/document')->assertForbidden();
        $this->actingAs($rival)->get('/api/agency/contract/document')->assertNotFound();
    }

    public function test_plans_can_be_listed_created_and_edited(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/api/admin/plans', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/plans', ['name' => 'Starter', 'employees' => 1, 'hours_per_month' => 40, 'price' => 900, 'billing_cycle' => 'quarterly'])
            ->assertCreated()->assertJsonPath('plan.currency', 'USD')->assertJsonPath('plan.billing_cycle', 'quarterly');
        $this->postJson('/api/admin/plans', ['name' => 'Starter'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/plans', ['name' => 'Bad', 'billing_cycle' => 'weekly', 'price' => -5])->assertUnprocessable()->assertJsonValidationErrors(['billing_cycle', 'price']);
        $retired = $this->plan(['name' => 'Retired', 'is_active' => false]);

        $this->getJson('/api/admin/plans')->assertOk()->assertJsonCount(2, 'plans');
        $this->getJson('/api/admin/plans?active=1')->assertOk()->assertJsonCount(1, 'plans')->assertJsonPath('plans.0.name', 'Starter');
        $this->patchJson("/api/admin/plans/{$retired->id}", ['is_active' => true, 'name' => 'Retired'])->assertOk()->assertJsonPath('plan.is_active', true);
        $this->patchJson("/api/admin/plans/{$retired->id}", ['name' => 'Starter'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_the_new_contract_feeds_the_dashboards_and_agency_pages(): void
    {
        Notification::fake();
        $plan = $this->plan(['employees' => 2, 'hours_per_month' => 50]);
        $this->create(['contract' => ['plan_id' => $plan->id, 'start_date' => $this->today->toDateString(), 'end_date' => $this->today->addDays(20)->toDateString(), 'auto_renew' => true]])->assertCreated();
        $agency = Agency::firstOrFail();
        $owner = User::where('email', 'olivia@example.com')->firstOrFail();

        $row = collect($this->actingAs($this->admin)->getJson('/api/admin/dashboard')->assertOk()->json('agency_rows'))->firstWhere('id', $agency->id);
        $this->assertSame(['Growth', 2, 50.0, $this->today->addDays(20)->toDateString(), true], [$row['plan'], $row['seats_allowed'], (float) $row['hours_included'], $row['renewal_date'], $row['has_contract']]);
        $overview = $this->getJson('/api/admin/dashboard')->json('contracts');
        $this->assertSame([true, 1, 0], [$overview['available'], $overview['with_contract'], $overview['without_contract']]);
        $this->assertSame([$agency->id, 20], [$overview['expiring'][0]['agency_id'], $overview['expiring'][0]['days_remaining']]);
        $this->getJson("/api/admin/agencies/{$agency->id}")->assertJsonPath('agency.contract.plan_name', 'Growth')->assertJsonPath('agency.limits.seats', 2);

        $d = $this->actingAs($owner)->getJson('/api/agency/dashboard')->assertOk()->json('dashboard');
        $this->assertSame([2, 50.0, 50.0], [$d['limits']['employees']['allowed'], (float) $d['limits']['hours']['allowed'], (float) $d['limits']['hours']['remaining']]);
        $this->assertSame(20, $d['contract']['days_remaining']);
        $this->assertSame($this->today->addDays(20)->toDateString(), $d['contract']['renews_on']);
        // A client sees the plan and dates but never the price.
        $client = $this->makeClient($agency);
        $c = $this->actingAs($client)->getJson('/api/agency/dashboard')->assertOk();
        $this->assertSame(['plan_name', 'status', 'ends_on', 'renews_on', 'days_remaining'], array_keys($c->json('dashboard.contract')));
        $this->assertStringNotContainsString('4800', $c->getContent());
    }

    public function test_the_wizard_endpoint_is_throttled_like_the_other_admin_creation_routes(): void
    {
        $this->actingAs($this->admin);

        foreach (range(1, 10) as $ignored) {
            $this->postJson('/api/admin/agency-owners', [])->assertUnprocessable();
        }
        $this->postJson('/api/admin/agency-owners', [])->assertStatus(429);
    }

    public function test_a_finished_contract_shows_its_dates_but_sets_no_limits(): void
    {
        $agency = $this->makeAgency('Northwind');
        AgencyContract::create(['agency_id' => $agency->id, 'status' => 'expired', 'start_date' => $this->today->subYear()->toDateString(), 'end_date' => $this->today->subDay()->toDateString(), 'employees' => 2, 'hours_per_month' => 40, 'plan_name' => 'Old']);

        $contract = app(\App\Services\AgencyContracts::class)->forAgency($agency->id);

        $this->assertSame(['expired', 'Old', null, null], [$contract['status'], $contract['plan_name'], $contract['seats_allowed'], $contract['hours_included']]);
        $this->assertSame(-1, \App\Services\AgencyContracts::daysRemaining($contract));
    }
}
