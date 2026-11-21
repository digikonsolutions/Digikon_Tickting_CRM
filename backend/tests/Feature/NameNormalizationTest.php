<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NameNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_names_are_tidied_when_accounts_are_created_and_updated(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin);

        $this->postJson('/api/auth/users', ['name' => '  JOHN   DOE ', 'email' => 'owner@example.com', 'role' => 'agency_owner', 'agency_name' => 'GHL   AGENCY'])->assertCreated();
        $this->assertSame('John Doe', User::where('email', 'owner@example.com')->value('name'));
        $this->assertSame('GHL Agency', Agency::query()->latest('id')->value('name'));

        $this->postJson('/api/admin/employees', ['name' => 'mAHNOOR sarfraz', 'email' => 'emp@example.com'])->assertCreated()->assertJsonPath('employee.name', 'Mahnoor Sarfraz');
        // A deliberately written name is not touched.
        $this->postJson('/api/admin/employees', ['name' => 'Flora McDonald', 'email' => 'flora@example.com'])->assertCreated()->assertJsonPath('employee.name', 'Flora McDonald');

        $employee = User::where('email', 'emp@example.com')->firstOrFail();
        $this->patchJson("/api/admin/users/{$employee->id}", ['name' => 'sARA khan'])->assertOk();
        $this->assertSame('Sara Khan', $employee->fresh()->name);
        $this->patchJson("/api/admin/employees/{$employee->id}", ['name' => 'ali RAZA'])->assertOk()->assertJsonPath('employee.name', 'ali RAZA');
    }

    public function test_agency_owner_creates_clients_with_tidy_names_and_users_edit_their_own_name(): void
    {
        Notification::fake();
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);

        $this->actingAs($owner)->postJson('/api/agency/users', ['name' => 'CAROL DANVERS', 'email' => 'carol@example.com', 'role' => 'agency_client'])->assertCreated();
        $this->assertSame('Carol Danvers', User::where('email', 'carol@example.com')->value('name'));

        $this->patchJson('/api/auth/profile', ['name' => '  jOHN   smith '])->assertOk();
        $this->assertSame('John Smith', $owner->fresh()->name);
    }

    public function test_normalize_names_command_previews_then_applies_and_never_touches_emails(): void
    {
        $messy = User::factory()->create(['name' => 'BOB  BUILDER', 'email' => 'BOB@Example.com']);
        $fine = User::factory()->create(['name' => 'Ada Lovelace']);
        $agency = Agency::create(['name' => 'northwind digital']);
        $tidyAgency = Agency::create(['name' => 'Acme Labs']);

        $this->artisan('app:normalize-names', ['--dry-run' => true])
            ->expectsOutputToContain('would change')
            ->assertExitCode(0);
        $this->assertSame('BOB  BUILDER', $messy->fresh()->name);
        $this->assertSame('northwind digital', $agency->fresh()->name);

        $this->artisan('app:normalize-names')->expectsOutputToContain('updated')->assertExitCode(0);
        $this->assertSame('Bob Builder', $messy->fresh()->name);
        $this->assertSame('BOB@Example.com', $messy->fresh()->email);
        $this->assertSame('Ada Lovelace', $fine->fresh()->name);
        $this->assertSame('Northwind Digital', $agency->fresh()->name);
        $this->assertSame('Acme Labs', $tidyAgency->fresh()->name);

        $this->artisan('app:normalize-names')->expectsOutputToContain('already tidy')->assertExitCode(0);
    }
}
