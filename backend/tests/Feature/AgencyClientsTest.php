<?php

namespace Tests\Feature;

use App\Models\AgencyContract;
use App\Models\ClientProfile;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/** The owner's Clients tab: profile fields, search, invitation, plan limit, impact and tenant scoping. */
class AgencyClientsTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWeek();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'company_name' => 'Analytical Engines', 'job_title' => 'Founder',
            'website' => 'https://example.com', 'phone' => '+44 20 7946 0000', 'preferred_contact_method' => 'phone',
            'timezone' => 'Europe/London', 'address' => '1 Engine Way', 'country' => 'United Kingdom', 'notes' => 'Prefers mornings.',
            ...$overrides,
        ];
    }

    public function test_the_owner_creates_a_client_with_a_profile_and_an_invitation(): void
    {
        Notification::fake();
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);

        $response = $this->actingAs($owner)->postJson('/api/agency/clients', $this->payload())->assertCreated();

        $client = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame($agency->id, $client->agency_id);
        $this->assertSame('agency_client', $client->role->value);
        $this->assertSame('Analytical Engines', $client->clientProfile->company_name);
        $this->assertNotNull($client->clientProfile->invited_at);
        $response->assertJsonPath('client.company_name', 'Analytical Engines')->assertJsonPath('client.status', 'active');
        Notification::assertSentTo($client, SetPasswordNotification::class);
    }

    public function test_the_invitation_can_be_held_back_and_sent_later(): void
    {
        Notification::fake();
        $owner = $this->makeOwner($this->makeAgency('Northwind'));

        $id = $this->actingAs($owner)->postJson('/api/agency/clients', $this->payload(['send_invitation' => false]))->assertCreated()->json('client.id');
        $client = User::findOrFail($id);
        Notification::assertNothingSent();
        $this->assertNull($client->clientProfile->invited_at);

        $this->postJson("/api/agency/clients/{$id}/invite")->assertOk();
        Notification::assertSentTo($client, SetPasswordNotification::class);
        $this->assertNotNull($client->clientProfile->fresh()->invited_at);
    }

    public function test_validation_names_the_field(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $this->makeClient($agency, 'Existing')->update(['email' => 'taken@example.com']);

        $this->actingAs($owner)->postJson('/api/agency/clients', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
        $this->postJson('/api/agency/clients', $this->payload(['email' => 'not-an-email']))->assertJsonValidationErrors('email');
        $this->postJson('/api/agency/clients', $this->payload(['email' => 'TAKEN@example.com']))->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'An account with this email already exists.');
        $this->postJson('/api/agency/clients', $this->payload(['website' => 'javascript:alert(1)']))->assertJsonValidationErrors('website');
        $this->postJson('/api/agency/clients', $this->payload(['phone' => 'call me']))->assertJsonValidationErrors('phone');
        $this->postJson('/api/agency/clients', $this->payload(['timezone' => 'Mars/Base']))->assertJsonValidationErrors('timezone');
        $this->postJson('/api/agency/clients', $this->payload(['notes' => str_repeat('x', 2001)]))->assertJsonValidationErrors('notes');
        $this->postJson('/api/agency/clients', $this->payload(['preferred_contact_method' => 'pigeon']))->assertJsonValidationErrors('preferred_contact_method');
    }

    public function test_the_list_searches_filters_and_counts_projects(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $ada = $this->makeClient($agency, 'Ada Lovelace');
        $ada->clientProfile()->create(['company_name' => 'Analytical Engines']);
        $bob = $this->makeClient($agency, 'Bob Builder');
        $bob->update(['is_active' => false]);
        $this->book($this->hold($this->makeEmployee('Emp'), $agency, $ada), 'in_progress', [[$this->today->addDays(2), 2]]);

        $all = $this->actingAs($owner)->getJson('/api/agency/clients')->assertOk();
        $this->assertSame(['Ada Lovelace', 'Bob Builder'], array_column($all->json('clients'), 'name'));
        $this->assertSame(1, $all->json('clients.0.projects_count'));
        $this->assertNotNull($all->json('clients.0.last_activity_at'));

        $this->getJson('/api/agency/clients?search=Analytical')->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.name', 'Ada Lovelace');
        $this->getJson('/api/agency/clients?search=bob@')->assertJsonCount(0, 'clients');
        $this->getJson('/api/agency/clients?status=inactive')->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.name', 'Bob Builder');
        $this->getJson('/api/agency/clients?per_page=1&page=2')->assertJsonPath('pagination.last_page', 2)->assertJsonPath('clients.0.name', 'Bob Builder');
        $this->getJson('/api/agency/clients?search=%25')->assertJsonCount(0, 'clients');       // wildcards are literal
    }

    public function test_the_plan_limits_active_client_accounts(): void
    {
        Notification::fake();
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        AgencyContract::create(['agency_id' => $agency->id, 'status' => 'active', 'start_date' => $this->today->toDateString(), 'plan_name' => 'Small', 'max_client_accounts' => 1]);
        $first = $this->actingAs($owner)->postJson('/api/agency/clients', $this->payload())->assertCreated()->json('client.id');

        $this->postJson('/api/agency/clients', $this->payload(['email' => 'two@example.com']))->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->getJson('/api/agency/clients')->assertJsonPath('limit', ['used' => 1, 'allowed' => 1, 'reached' => true]);

        // Deactivating frees the seat; reactivating is blocked again once it is taken.
        $this->patchJson("/api/agency/clients/{$first}", ['is_active' => false])->assertOk()->assertJsonPath('client.status', 'inactive');
        $this->postJson('/api/agency/clients', $this->payload(['email' => 'two@example.com']))->assertCreated();
        $this->patchJson("/api/agency/clients/{$first}", ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('limit');
    }

    public function test_editing_updates_the_profile_and_a_new_email_sends_an_invitation(): void
    {
        Notification::fake();
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $id = $this->actingAs($owner)->postJson('/api/agency/clients', $this->payload())->assertCreated()->json('client.id');

        $this->patchJson("/api/agency/clients/{$id}", ['company_name' => 'New Co', 'notes' => ''])->assertOk()
            ->assertJsonPath('client.company_name', 'New Co')->assertJsonPath('client.notes', null)->assertJsonPath('client.password_setup_email_sent', false);
        $this->patchJson("/api/agency/clients/{$id}", ['email' => 'new@example.com'])->assertOk()->assertJsonPath('client.password_setup_email_sent', true);
        $this->assertSame('Ada Lovelace', User::findOrFail($id)->name);
        $this->assertSame(1, ClientProfile::where('user_id', $id)->count());
    }

    public function test_the_detail_reports_what_deactivating_would_do(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $client = $this->makeClient($agency);
        $assignment = $this->hold($this->makeEmployee('Emp'), $agency, $client);
        $this->book($assignment, 'in_progress', [[$this->today->addDays(2), 2], [$this->today->addDays(3), 3]]);

        $response = $this->actingAs($owner)->getJson("/api/agency/clients/{$client->id}")->assertOk();

        $response->assertJsonPath('client.impact', ['held_employees' => 1, 'open_projects' => 1, 'booked_hours' => 5])
            ->assertJsonCount(1, 'client.projects');

        $this->patchJson("/api/agency/clients/{$client->id}", ['is_active' => false])->assertOk();
        $this->assertSame('ended', $assignment->fresh()->status);
        $this->assertSame(['cancelled'], \Illuminate\Support\Facades\DB::table('work_items')->where('assignment_id', $assignment->id)->pluck('status')->all());
    }

    public function test_deleting_removes_the_client(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $client = $this->makeClient($agency);

        $this->actingAs($owner)->deleteJson("/api/agency/clients/{$client->id}")->assertOk();
        $this->assertNull(User::find($client->id));
    }

    public function test_other_agencies_and_other_roles_get_nothing(): void
    {
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $client = $this->makeClient($agency);
        $rivalOwner = $this->makeOwner($this->makeAgency('Rival'), 'Rival Owner');
        $rivalClient = $this->makeClient($rivalOwner->agency, 'Rival Client');

        // Another agency's client is a 404 for every verb, and never appears in the list.
        $this->actingAs($owner);
        $this->getJson("/api/agency/clients/{$rivalClient->id}")->assertNotFound();
        $this->patchJson("/api/agency/clients/{$rivalClient->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->postJson("/api/agency/clients/{$rivalClient->id}/invite")->assertNotFound();
        $this->deleteJson("/api/agency/clients/{$rivalClient->id}")->assertNotFound();
        $this->assertSame(['Client'], array_column($this->getJson('/api/agency/clients')->json('clients'), 'name'));
        $this->assertSame('Rival Client', $rivalClient->fresh()->name);

        // Owners cannot reach staff or other owners through this route either.
        $this->getJson("/api/agency/clients/{$rivalOwner->id}")->assertNotFound();
        $this->getJson("/api/agency/clients/{$owner->id}")->assertNotFound();

        // Clients and unauthenticated callers are refused.
        $this->actingAs($client)->getJson('/api/agency/clients')->assertForbidden();
        $this->actingAs($client)->postJson('/api/agency/clients', $this->payload())->assertForbidden();
        auth()->forgetGuards();
        $this->getJson('/api/agency/clients')->assertUnauthorized();
    }
}
