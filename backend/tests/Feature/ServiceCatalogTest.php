<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AgencyRequest;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $client;

    private User $employee;

    private EmployeeAssignment $assignment;

    private CarbonImmutable $mon;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests cover the engine behind the older flow where an agency's own dates are auto-scheduled.
        config(['scheduling.admin_only_scheduling' => false]);
        config(['scheduling.require_timeline_review' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-12-07 08:00', config('app.timezone')));
        $this->setUpWeek();
        $this->mon = $this->today;
        $agency = $this->makeAgency('Acme');
        $this->owner = $this->makeOwner($agency);
        $this->client = $this->makeClient($agency);
        $this->employee = $this->makeEmployee('Emma');
        $this->assignment = $this->hold($this->employee, $agency, $this->owner);
    }

    /** @return array<string, mixed> */
    private function project(array $overrides = []): array
    {
        return [
            'title' => 'Lead follow-up', 'description' => 'Workflow: a form is submitted.', 'estimated_hours' => 6,
            'start_date' => $this->mon->toDateString(), 'due_date' => $this->mon->addDays(10)->toDateString(),
            'assignment_id' => $this->assignment->id,
            'service_type' => 'workflow',
            'details' => ['trigger' => 'form_submitted', 'actions' => ['send_email', 'add_tag'], 'goal' => 'Follow up fast', 'reference_url' => 'https://example.com/flow', 'notes' => 'Use our tone.'],
            ...$overrides,
        ];
    }

    private function createProject(array $overrides = []): WorkItem
    {
        $id = $this->actingAs($this->owner)->postJson('/api/agency/projects', $this->project($overrides))->assertCreated()->json('project.id');

        return WorkItem::findOrFail($id);
    }

    public function test_the_catalog_is_served_to_owners_and_clients_only(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/agency/service-catalog')->assertOk();
        $keys = collect($response->json('types'))->pluck('key');
        foreach (['workflow', 'funnel', 'crm', 'calendar', 'campaign', 'integration', 'reporting', 'snapshot', 'other'] as $expected) {
            $this->assertContains($expected, $keys);
        }
        $this->assertSame('GHL builder', $response->json('employee_request.roles.ghl_builder'));
        $this->actingAs($this->client)->getJson('/api/agency/service-catalog')->assertOk();
        $this->actingAs($this->employee)->getJson('/api/agency/service-catalog')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/agency/service-catalog')->assertUnauthorized();
    }

    public function test_a_project_stores_its_service_and_answers_and_returns_them(): void
    {
        $this->actingAs($this->owner)->postJson('/api/agency/projects', $this->project())->assertCreated()
            ->assertJsonPath('project.service_type', 'workflow')
            ->assertJsonPath('project.details.trigger', 'form_submitted')
            ->assertJsonPath('project.details.actions.1', 'add_tag')
            ->assertJsonPath('project.details.notes', 'Use our tone.');
        $this->assertSame('workflow', WorkItem::query()->sole()->service_type);
    }

    public function test_a_project_without_a_service_still_works_and_reports_nulls(): void
    {
        $this->actingAs($this->owner)->postJson('/api/agency/projects', $this->project(['service_type' => null, 'details' => null]))->assertCreated()
            ->assertJsonPath('project.service_type', null)->assertJsonPath('project.details', null);
        $body = $this->project();
        unset($body['service_type'], $body['details']);
        $this->postJson('/api/agency/projects', $body)->assertCreated()->assertJsonPath('project.service_type', null);
    }

    public function test_project_answers_are_validated_per_service(): void
    {
        $this->actingAs($this->owner);
        $post = fn (array $overrides) => $this->postJson('/api/agency/projects', $this->project($overrides));

        $post(['service_type' => 'spreadsheet'])->assertUnprocessable()->assertJsonValidationErrors('service_type');
        $post(['details' => ['actions' => ['send_email']]])->assertUnprocessable()->assertJsonValidationErrors('details.trigger');
        $post(['details' => ['trigger' => 'form_submitted']])->assertUnprocessable()->assertJsonValidationErrors('details.actions');
        $post(['details' => ['trigger' => 'form_submitted', 'actions' => []]])->assertUnprocessable()->assertJsonValidationErrors('details.actions');
        $post(['details' => ['trigger' => 'telepathy', 'actions' => ['send_email']]])->assertUnprocessable()->assertJsonValidationErrors('details.trigger');
        $post(['details' => ['trigger' => 'manual', 'actions' => ['teleport']]])->assertUnprocessable()->assertJsonValidationErrors('details.actions.0');
        $post(['details' => ['trigger' => 'manual', 'actions' => ['send_sms'], 'reference_url' => 'javascript:alert(1)']])->assertUnprocessable()->assertJsonValidationErrors('details.reference_url');
        $post(['details' => ['trigger' => 'manual', 'actions' => ['send_sms'], 'goal' => str_repeat('x', 201)]])->assertUnprocessable()->assertJsonValidationErrors('details.goal');
        // A field of another service does not belong here.
        $post(['details' => ['trigger' => 'manual', 'actions' => ['send_sms'], 'funnel_type' => 'sales']])->assertUnprocessable()->assertJsonValidationErrors('details');
        // Answers need a service.
        $post(['service_type' => null, 'details' => ['trigger' => 'manual']])->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertSame(0, WorkItem::query()->count());

        // The "something else" service takes only notes.
        $post(['service_type' => 'other', 'details' => ['notes' => 'A one-off.']])->assertCreated();
        $post(['service_type' => 'other', 'details' => ['goal' => 'x']])->assertUnprocessable();
    }

    public function test_each_service_has_a_working_set_of_rules(): void
    {
        // Every field of every type accepts one valid sample, so a mistake in the catalog is caught here.
        $this->actingAs($this->owner);
        $hours = 0.5;
        foreach (config('service_catalog.types') as $key => $type) {
            $details = [];
            foreach ($type['fields'] as $field) {
                $details[$field['key']] = match ($field['type']) {
                    'select' => array_key_first($field['options']),
                    'checkbox' => [array_key_first($field['options'])],
                    'url' => 'https://example.com/x',
                    default => 'Sample',
                };
            }
            $this->postJson('/api/agency/projects', $this->project(['service_type' => $key, 'details' => $details ?: null, 'estimated_hours' => $hours]))
                ->assertCreated("service {$key} rejected its own sample answers");
        }
    }

    public function test_tickets_use_the_lighter_field_set_and_never_require_answers(): void
    {
        $project = $this->createProject();
        $url = "/api/agency/projects/{$project->id}/tickets";
        $ticket = ['title' => 'Add SMS step', 'description' => 'Please add a text message.', 'estimated_hours' => 1];

        $this->actingAs($this->owner)->postJson($url, $ticket)->assertCreated()->assertJsonPath('ticket.service_type', null);
        $this->postJson($url, [...$ticket, 'service_type' => 'workflow', 'details' => ['actions' => ['send_sms'], 'notes' => 'Evening only.']])->assertCreated()
            ->assertJsonPath('ticket.service_type', 'workflow')->assertJsonPath('ticket.details.actions.0', 'send_sms');
        // `goal` is a project-only field of the workflow service.
        $this->postJson($url, [...$ticket, 'service_type' => 'workflow', 'details' => ['goal' => 'Too much']])->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->postJson($url, [...$ticket, 'service_type' => 'workflow', 'details' => ['trigger' => 'nope']])->assertUnprocessable()->assertJsonValidationErrors('details.trigger');
        $this->postJson($url, [...$ticket, 'details' => ['notes' => 'orphan']])->assertUnprocessable()->assertJsonValidationErrors('details');
    }

    public function test_an_owner_edits_a_projects_service_and_answers_and_changing_service_drops_old_answers(): void
    {
        $project = $this->createProject();
        $url = "/api/agency/projects/{$project->id}";

        $this->actingAs($this->owner)->patchJson($url, ['details' => ['trigger' => 'tag_added', 'actions' => ['notify_team']]])->assertOk()
            ->assertJsonPath('project.details.trigger', 'tag_added')->assertJsonPath('project.service_type', 'workflow');
        // Wrong for the current service.
        $this->patchJson($url, ['details' => ['trigger' => 'nope', 'actions' => ['notify_team']]])->assertUnprocessable()->assertJsonValidationErrors('details.trigger');

        $this->patchJson($url, ['service_type' => 'funnel'])->assertOk()->assertJsonPath('project.service_type', 'funnel')->assertJsonPath('project.details', null);
        $this->patchJson($url, ['service_type' => 'crm', 'details' => ['pipeline_type' => 'sales']])->assertOk()->assertJsonPath('project.details.pipeline_type', 'sales');
        // Only owners edit.
        $this->actingAs($this->client)->patchJson($url, ['details' => ['pipeline_type' => 'support']])->assertForbidden();
    }

    public function test_changing_the_estimate_while_editing_replans_the_hours(): void
    {
        $project = $this->createProject(['estimated_hours' => 6]);
        $this->assertSame(6.0, (float) $project->allocations()->sum('hours'));

        $this->actingAs($this->owner)->patchJson("/api/agency/projects/{$project->id}", ['estimated_hours' => 14, 'details' => ['trigger' => 'manual', 'actions' => ['webhook']]])->assertOk()
            ->assertJsonPath('project.estimated_hours', '14.00')->assertJsonPath('project.details.trigger', 'manual');

        $this->assertSame(14.0, (float) $project->allocations()->sum('hours'));
        $this->assertLessThanOrEqual(6.0, (float) $project->allocations()->max('hours'));
    }

    public function test_closed_projects_cannot_be_edited(): void
    {
        foreach (['completed', 'cancelled'] as $status) {
            $project = $this->createProject();
            $project->update(['status' => $status]);
            $url = "/api/agency/projects/{$project->id}";

            $this->actingAs($this->owner)->patchJson($url, ['details' => ['trigger' => 'manual', 'actions' => ['webhook']]])->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->patchJson($url, ['service_type' => 'funnel'])->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->patchJson($url, ['estimated_hours' => 9])->assertUnprocessable()->assertJsonValidationErrors('status');
            $this->assertSame('workflow', $project->fresh()->service_type);
        }
    }

    public function test_tickets_are_edited_with_details_and_replanned_and_closed_ones_are_refused(): void
    {
        $project = $this->createProject(['estimated_hours' => 12]);
        $url = "/api/agency/projects/{$project->id}/tickets";
        $id = $this->actingAs($this->owner)->postJson($url, ['title' => 'Step', 'description' => 'Do it.', 'estimated_hours' => 2, 'service_type' => 'workflow', 'details' => ['actions' => ['send_email']]])->json('ticket.id');
        $other = $this->postJson($url, ['title' => 'Second', 'description' => 'Then this.', 'estimated_hours' => 2])->json('ticket.id');
        $secondStart = Ticket::find($other)->start_date->toDateString();

        // Longer first ticket pushes the second one later: the planner ran again.
        $this->patchJson("/api/agency/tickets/{$id}", ['estimated_hours' => 8, 'details' => ['actions' => ['send_sms'], 'trigger' => 'manual']])->assertOk()
            ->assertJsonPath('ticket.details.actions.0', 'send_sms');
        $this->assertGreaterThan($secondStart, Ticket::find($other)->start_date->toDateString());
        $this->patchJson("/api/agency/tickets/{$id}", ['service_type' => 'funnel'])->assertOk()->assertJsonPath('ticket.details', null);
        $this->patchJson("/api/agency/tickets/{$id}", ['details' => ['actions' => ['send_sms']]])->assertUnprocessable();

        foreach (['done', 'cancelled'] as $status) {
            Ticket::find($id)->update(['status' => $status]);
            $this->patchJson("/api/agency/tickets/{$id}", ['details' => ['funnel_type' => 'sales']])->assertUnprocessable();
            $this->patchJson("/api/agency/tickets/{$id}", ['title' => 'Renamed'])->assertUnprocessable();
        }
    }

    public function test_request_an_employee_takes_structured_answers(): void
    {
        User::factory()->create(['role' => Role::Admin]);
        $answers = ['role' => 'automation_specialist', 'hours_per_week' => 20, 'duration_weeks' => 8];
        $this->actingAs($this->owner)->postJson('/api/agency/requests', ['type' => 'employee', 'message' => 'Start in January.', 'details' => $answers])->assertCreated()
            ->assertJsonPath('request.details.role', 'automation_specialist')->assertJsonPath('request.details.hours_per_week', 20);

        $this->assertSame(20, AgencyRequest::query()->sole()->details['hours_per_week']);
        // The admins read the answers, not just the notes.
        $notice = DatabaseNotification::query()->get()->pluck('data.body')->implode(' ');
        $this->assertStringContainsString('Automation specialist, 20 h per week for 8 week(s). Start in January.', $notice);
    }

    public function test_request_an_employee_rejects_bad_answers_and_details_on_other_requests(): void
    {
        // Many invalid attempts in a row: the request throttle is not what is under test.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs($this->owner);
        $post = fn (array $details, string $type = 'employee') => $this->postJson('/api/agency/requests', ['type' => $type, 'details' => $details]);

        $post(['role' => 'wizard', 'hours_per_week' => 10, 'duration_weeks' => 4])->assertUnprocessable()->assertJsonValidationErrors('details.role');
        $post(['role' => 'designer', 'hours_per_week' => 0, 'duration_weeks' => 4])->assertUnprocessable()->assertJsonValidationErrors('details.hours_per_week');
        $post(['role' => 'designer', 'hours_per_week' => 37, 'duration_weeks' => 4])->assertUnprocessable()->assertJsonValidationErrors('details.hours_per_week');
        $post(['role' => 'designer', 'hours_per_week' => 10, 'duration_weeks' => 53])->assertUnprocessable()->assertJsonValidationErrors('details.duration_weeks');
        $post(['role' => 'designer'])->assertUnprocessable()->assertJsonValidationErrors('details.hours_per_week');
        $post(['role' => 'designer', 'hours_per_week' => 10, 'duration_weeks' => 4, 'extra' => 'x'])->assertUnprocessable();
        $post(['role' => 'designer', 'hours_per_week' => 10, 'duration_weeks' => 4], 'more_hours')->assertUnprocessable()->assertJsonValidationErrors('details');
        // Still fine the old way, with no answers at all, and clients still cannot ask for an employee.
        $this->postJson('/api/agency/requests', ['type' => 'employee', 'message' => 'Anyone free?'])->assertCreated();
        $this->actingAs($this->client)->postJson('/api/agency/requests', ['type' => 'employee', 'details' => ['role' => 'designer', 'hours_per_week' => 5, 'duration_weeks' => 2]])->assertForbidden();
    }

    public function test_holding_an_employee_and_creating_a_project_on_them_still_work_with_a_service(): void
    {
        $spare = $this->makeEmployee('Spare');
        $this->actingAs($this->owner)->postJson('/api/agency/assignments', ['employee_id' => $spare->id])->assertCreated();
        $assignment = EmployeeAssignment::query()->where('employee_id', $spare->id)->firstOrFail();

        $this->postJson('/api/agency/projects', $this->project(['assignment_id' => $assignment->id, 'service_type' => 'snapshot', 'details' => ['action' => 'build_snapshot', 'includes' => ['workflows', 'funnels']]]))
            ->assertCreated()->assertJsonPath('project.employee.id', $spare->id)->assertJsonPath('project.details.includes.1', 'funnels');
    }
}
