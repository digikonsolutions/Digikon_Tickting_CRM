<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureActiveAgencyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'An active agency account is required to access this resource.';

    /**
     * @return array<string, array{string, string, Role}>
     */
    public static function guardedRoutes(): array
    {
        return [
            'team' => ['GET', '/api/agency/team', Role::AgencyClient],
            'directory' => ['GET', '/api/agency/directory', Role::AgencyClient],
            'profile' => ['PATCH', '/api/agency/profile', Role::AgencyClient],
            'list users' => ['GET', '/api/agency/users', Role::AgencyOwner],
            'create user' => ['POST', '/api/agency/users', Role::AgencyOwner],
            'update user' => ['PATCH', '/api/agency/users/1', Role::AgencyOwner],
            'delete user' => ['DELETE', '/api/agency/users/1', Role::AgencyOwner],
            'available employees' => ['GET', '/api/agency/employees/available', Role::AgencyClient],
            'hold employee' => ['POST', '/api/agency/assignments', Role::AgencyClient],
            'list assignments' => ['GET', '/api/agency/assignments', Role::AgencyClient],
            'capacity' => ['GET', '/api/agency/capacity', Role::AgencyClient],
            'list projects' => ['GET', '/api/agency/projects', Role::AgencyClient],
            'show project' => ['GET', '/api/agency/projects/{project}', Role::AgencyClient],
            'create project' => ['POST', '/api/agency/projects', Role::AgencyClient],
            'cancel project' => ['PATCH', '/api/agency/projects/{project}/cancel', Role::AgencyClient],
        ];
    }

    /**
     * @dataProvider guardedRoutes
     */
    public function test_suspended_agency_users_get_403_on_guarded_routes(string $method, string $uri, Role $role): void
    {
        $agency = Agency::create(['name' => 'Suspended', 'status' => 'suspended']);
        $user = User::factory()->create(['role' => $role, 'agency_id' => $agency->id]);

        $this->actingAs($user)->json($method, $this->resolve($uri))
            ->assertForbidden()
            ->assertJsonPath('message', self::MESSAGE);
    }

    /**
     * @dataProvider guardedRoutes
     */
    public function test_users_without_an_agency_get_403_on_guarded_routes(string $method, string $uri, Role $role): void
    {
        $user = User::factory()->create(['role' => $role, 'agency_id' => null]);

        $this->actingAs($user)->json($method, $this->resolve($uri))
            ->assertForbidden()
            ->assertJsonPath('message', self::MESSAGE);
    }

    /**
     * @dataProvider guardedRoutes
     */
    public function test_guests_get_401_and_wrong_roles_keep_their_own_403(string $method, string $uri, Role $role): void
    {
        $this->json($method, $this->resolve($uri))->assertUnauthorized();

        $agency = Agency::create(['name' => 'Active']);
        $wrongRole = $role === Role::AgencyOwner ? Role::AgencyClient : Role::Employee;
        $this->actingAs(User::factory()->create(['role' => $wrongRole, 'agency_id' => $agency->id]))
            ->json($method, $this->resolve($uri))
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to access this resource.');
    }

    public function test_active_agency_users_pass_the_guard(): void
    {
        $agency = Agency::create(['name' => 'Active']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);

        $this->actingAs($owner)->getJson('/api/agency/users')->assertOk();
        $this->actingAs($client)->getJson('/api/agency/projects')->assertOk();
        $this->actingAs($client)->getJson('/api/agency/team')->assertOk();
        $this->actingAs($client)->getJson('/api/agency/directory')->assertOk();
    }

    public function test_ticket_routes_keep_returning_404_for_suspended_agencies(): void
    {
        $agency = Agency::create(['name' => 'Soon suspended']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = EmployeeAssignment::create([
            'holder_id' => $client->id,
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);
        $project = WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => 'Project',
            'description' => 'Description.',
            'estimated_hours' => 1,
            'start_date' => now()->addWeek()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'status' => 'scheduled',
        ]);
        $agency->update(['status' => 'suspended']);

        $this->actingAs($client)->getJson('/api/agency/projects/'.$project->id.'/tickets')->assertNotFound();
    }

    /**
     * Route-model binding runs before route middleware, so URIs with a project
     * placeholder need a project that really exists.
     */
    private function resolve(string $uri): string
    {
        if (! str_contains($uri, '{project}')) {
            return $uri;
        }

        $project = WorkItem::query()->first() ?? $this->makeProject();

        return str_replace('{project}', (string) $project->id, $uri);
    }

    private function makeProject(): WorkItem
    {
        $agency = Agency::create(['name' => 'Project owner agency']);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $assignment = EmployeeAssignment::create([
            'holder_id' => $client->id,
            'agency_id' => $agency->id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'active_employee_id' => $employee->id,
            'started_at' => now(),
        ]);

        return WorkItem::create([
            'agency_id' => $agency->id,
            'client_id' => $client->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => 'Project',
            'description' => 'Description.',
            'estimated_hours' => 1,
            'start_date' => now()->addWeek()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'status' => 'scheduled',
        ]);
    }
}