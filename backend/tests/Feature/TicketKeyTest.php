<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\UserAccountService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_numbering_per_agency(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture('Alpha Agency');

        $this->assertSame('ALPH', $agency->ticket_prefix);

        $t1 = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'First ticket',
            'description' => 'First desc',
            'estimated_hours' => 2,
        ])->assertCreated()
            ->assertJsonPath('ticket.key', 'ALPH-1')
            ->assertJsonPath('ticket.number', 1);

        $t2 = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Second ticket',
            'description' => 'Second desc',
            'estimated_hours' => 2,
        ])->assertCreated()
            ->assertJsonPath('ticket.key', 'ALPH-2')
            ->assertJsonPath('ticket.number', 2);

        $agency->refresh();
        $this->assertSame(2, $agency->ticket_sequence);
    }

    public function test_two_agencies_each_starting_at_one(): void
    {
        [$agencyA, $ownerA, $clientA, $employeeA, $projectA] = $this->projectFixture('Alpha Corp');
        [$agencyB, $ownerB, $clientB, $employeeB, $projectB] = $this->projectFixture('Beta Corp');

        $this->assertSame('ALPH', $agencyA->ticket_prefix);
        $this->assertSame('BETA', $agencyB->ticket_prefix);

        $resA1 = $this->actingAs($clientA)->postJson("/api/agency/projects/{$projectA->id}/tickets", [
            'title' => 'A1',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->assertCreated()->json('ticket');

        $resB1 = $this->actingAs($clientB)->postJson("/api/agency/projects/{$projectB->id}/tickets", [
            'title' => 'B1',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->assertCreated()->json('ticket');

        $resA2 = $this->actingAs($clientA)->postJson("/api/agency/projects/{$projectA->id}/tickets", [
            'title' => 'A2',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->assertCreated()->json('ticket');

        $this->assertSame('ALPH-1', $resA1['key']);
        $this->assertSame(1, $resA1['number']);
        $this->assertSame('BETA-1', $resB1['key']);
        $this->assertSame(1, $resB1['number']);
        $this->assertSame('ALPH-2', $resA2['key']);
        $this->assertSame(2, $resA2['number']);
    }

    public function test_prefix_collision_resolution(): void
    {
        $prefix1 = Agency::generatePrefix('Northwind');
        $this->assertSame('NORT', $prefix1);

        Agency::create(['name' => 'Northwind', 'ticket_prefix' => $prefix1]);

        $prefix2 = Agency::generatePrefix('Northwest');
        $this->assertSame('NORT1', $prefix2);

        Agency::create(['name' => 'Northwest', 'ticket_prefix' => $prefix2]);

        $prefix3 = Agency::generatePrefix('Northern');
        $this->assertSame('NORT2', $prefix3);
    }

    public function test_prefix_is_immutable_once_tickets_exist(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture('Acme Agency');

        $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Ticket 1',
            'description' => 'Desc',
            'estimated_hours' => 2,
        ])->assertCreated();

        $this->expectException(DomainException::class);
        $agency->update(['ticket_prefix' => 'NEWP']);
    }

    public function test_user_account_service_generates_prefix_on_agency_creation(): void
    {
        $service = app(UserAccountService::class);
        $user = $service->createInvitedUser([
            'name' => 'Owner Person',
            'email' => 'owner.new@example.com',
            'role' => 'agency_owner',
            'agency_name' => 'Dynamic Agency',
        ]);

        $this->assertNotNull($user->agency);
        $this->assertSame('DYNA', $user->agency->ticket_prefix);
        $this->assertSame(0, $user->agency->ticket_sequence);
    }

    public function test_get_ticket_by_key_and_authorization(): void
    {
        [$agency, $owner, $client, $employee, $project] = $this->projectFixture('Zephyr Hub');

        $ticketRes = $this->actingAs($client)->postJson("/api/agency/projects/{$project->id}/tickets", [
            'title' => 'Keyed ticket',
            'description' => 'Details here',
            'estimated_hours' => 3,
        ])->assertCreated()->json('ticket');

        $key = $ticketRes['key'];
        $this->assertSame('ZEPH-1', $key);

        // Unknown key 404
        $this->actingAs($client)->getJson('/api/tickets/key/ZEPH-999')->assertNotFound();
        $this->actingAs($client)->getJson('/api/tickets/key/UNKNOWN-1')->assertNotFound();
        $this->actingAs($client)->getJson('/api/tickets/key/invalidkey')->assertNotFound();

        // Client in same agency can view
        $this->actingAs($client)->getJson("/api/tickets/key/{$key}")
            ->assertOk()
            ->assertJsonPath('ticket.key', $key)
            ->assertJsonPath('project.title', $project->title)
            ->assertJsonPath('project.agency_name', $agency->name);

        // Owner in same agency can view
        $this->actingAs($owner)->getJson("/api/tickets/key/{$key}")->assertOk();

        // Assigned employee can view
        $this->actingAs($employee)->getJson("/api/tickets/key/{$key}")->assertOk();

        // Admin can view
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->getJson("/api/tickets/key/{$key}")->assertOk();

        // Client in other agency gets 404
        $otherAgency = Agency::create(['name' => 'Other Tenant', 'ticket_prefix' => 'OTHR']);
        $otherClient = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $otherAgency->id]);
        $this->actingAs($otherClient)->getJson("/api/tickets/key/{$key}")->assertNotFound();

        // Unassigned employee gets 404
        $otherEmployee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($otherEmployee)->getJson("/api/tickets/key/{$key}")->assertNotFound();
    }

    /**
     * @return array{Agency, User, User, User, WorkItem}
     */
    private function projectFixture(string $agencyName = 'Northwind', float $hours = 10): array
    {
        $prefix = Agency::generatePrefix($agencyName);
        $agency = Agency::create(['name' => $agencyName, 'ticket_prefix' => $prefix]);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeek();

        $assignment = EmployeeAssignment::create([
            'holder_id' => $client->id,
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);

        $workItem = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => 'Project',
            'description' => 'Project description.',
            'estimated_hours' => $hours,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDays(5)->toDateString(),
            'status' => 'scheduled',
        ]);

        $remaining = (int) round($hours * 100);
        for ($date = $monday; $remaining > 0; $date = $date->addDay()) {
            if ($date->dayOfWeek === CarbonImmutable::SUNDAY) {
                continue;
            }

            $units = min(600, $remaining);
            $workItem->allocations()->create([
                'employee_id' => $employee->id,
                'work_date' => $date->toDateString(),
                'hours' => number_format($units / 100, 2, '.', ''),
            ]);
            $remaining -= $units;
        }

        return [$agency, $owner, $client, $employee, $workItem];
    }
}
