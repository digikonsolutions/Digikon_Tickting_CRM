<?php

namespace Tests\Feature;

use App\Models\EmployeeAssignment;
use App\Models\ProjectService;
use App\Models\User;
use App\Models\WorkItem;
use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** Projects that ask for several GoHighLevel services, each with its own answers. */
class ProjectServicesTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    private function body(array $extra = []): array
    {
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(6);

        return ['title' => 'Full system', 'description' => 'Everything.', 'estimated_hours' => 10, 'start_date' => $monday->toDateString(), 'due_date' => $monday->addDays(5)->toDateString(),
            'assignment_id' => $this->project->assignment_id, ...$extra];
    }

    private function services(): array
    {
        return [
            ['key' => 'workflow', 'details' => ['trigger' => 'form_submitted', 'actions' => ['send_email', 'add_tag'], 'notes' => 'Evenings only'], 'estimated_hours' => 4],
            ['key' => 'ai_agent', 'details' => ['agent_type' => 'chatbot', 'purpose' => ['book_appointments']], 'estimated_hours' => 6],
        ];
    }

    public function test_the_catalog_lists_groups_packages_and_the_new_services(): void
    {
        $res = $this->actingAs($this->u['owner'])->getJson('/api/agency/service-catalog')->assertOk();
        $keys = collect($res->json('types'))->pluck('key');
        foreach (['ai_agent', 'forms', 'sms_compliance', 'reputation', 'phone', 'payments', 'membership', 'community', 'social', 'chat_widget', 'migration', 'audit', 'training', 'retainer'] as $key) {
            $this->assertContains($key, $keys);
        }
        $groups = collect($res->json('groups'))->pluck('key')->all();
        foreach ($res->json('types') as $type) {
            $this->assertContains($type['group'], $groups, "{$type['key']} has a group");
        }
        $full = collect($res->json('packages'))->firstWhere('key', 'full_automation');
        $this->assertContains('ai_agent', $full['services']);
        foreach ($res->json('packages') as $package) {
            foreach ($package['services'] as $service) {
                $this->assertContains($service, $keys, "package {$package['key']} names a real service");
            }
        }
    }

    public function test_a_project_stores_several_services_and_mirrors_the_first(): void
    {
        $res = $this->actingAs($this->u['client'])->postJson('/api/agency/projects', $this->body(['services' => $this->services()]))->assertCreated();
        $this->assertSame(['workflow', 'ai_agent'], array_column($res->json('project.services'), 'key'));
        $this->assertSame('workflow', $res->json('project.service_type')); // the older single-service view
        $this->assertSame('form_submitted', $res->json('project.details.trigger'));
        $id = $res->json('project.id');
        $this->assertSame(2, ProjectService::query()->where('work_item_id', $id)->count());

        $show = $this->actingAs($this->u['owner'])->getJson("/api/agency/projects/{$id}")->assertOk();
        $this->assertSame(['chatbot'], [$show->json('project.services.1.details.agent_type')]);
        $this->assertSame(3, $show->json('project.services.0.answers'));
        // Lists carry labels and counts, not the answers.
        $list = $this->actingAs($this->u['owner'])->getJson('/api/agency/projects')->assertOk()->json('projects');
        $row = collect($list)->firstWhere('id', $id);
        $this->assertNull($row['services'][0]['details']);
        $this->assertSame('AI agent (chat or voice)', $row['services'][1]['label']);
    }

    public function test_every_service_is_validated_with_its_own_rules(): void
    {
        $this->actingAs($this->u['client']);
        $post = fn (array $services) => $this->postJson('/api/agency/projects', $this->body(['services' => $services]));
        $post([['key' => 'spreadsheet']])->assertUnprocessable()->assertJsonValidationErrors(['services.0.key']);
        $post([['key' => 'workflow', 'details' => ['actions' => ['send_email']]]])->assertUnprocessable()->assertJsonValidationErrors(['services.0.details.trigger']);
        $post([$this->services()[0], ['key' => 'ai_agent', 'details' => ['agent_type' => 'robot', 'purpose' => ['support']]]])->assertUnprocessable()->assertJsonValidationErrors(['services.1.details.agent_type']);
        $post([['key' => 'workflow', 'details' => ['trigger' => 'manual', 'actions' => ['send_sms'], 'funnel_type' => 'sales']]])->assertUnprocessable()->assertJsonValidationErrors(['services.0.details']);
        $post([$this->services()[0], $this->services()[0]])->assertUnprocessable()->assertJsonValidationErrors(['services.1.key']);
        $post(array_fill(0, 16, ['key' => 'other']))->assertUnprocessable();
        $post([['key' => 'workflow', 'details' => ['trigger' => 'manual', 'actions' => ['send_sms']], 'estimated_hours' => 0.1]])->assertUnprocessable()->assertJsonValidationErrors(['services.0.estimated_hours']);
        $this->assertSame(1, WorkItem::query()->count()); // only the fixture project
    }

    public function test_a_project_with_only_the_old_service_fields_gets_one_service(): void
    {
        $res = $this->actingAs($this->u['client'])->postJson('/api/agency/projects', $this->body(['service_type' => 'workflow', 'details' => ['trigger' => 'manual', 'actions' => ['send_sms']]]))->assertCreated();
        $this->assertSame(['workflow'], array_column($res->json('project.services'), 'key'));
        $plain = $this->actingAs($this->u['client'])->postJson('/api/agency/projects', $this->body(['title' => 'Plain']))->assertCreated();
        $this->assertSame([], $plain->json('project.services'));
    }

    public function test_services_follow_the_project_and_stay_inside_its_agency(): void
    {
        $id = $this->actingAs($this->u['client'])->postJson('/api/agency/projects', $this->body(['services' => $this->services()]))->assertCreated()->json('project.id');
        $this->actingAs($this->outsider())->getJson("/api/agency/projects/{$id}")->assertNotFound();
        $admin = $this->actingAs($this->u['admin'])->getJson("/api/admin/projects/{$id}")->assertOk();
        $this->assertSame(2, count($admin->json('project.services')));
        $this->assertNotNull($admin->json('project.services.0.details'));
        DB::table('work_items')->where('id', $id)->delete();
        $this->assertSame(0, ProjectService::query()->where('work_item_id', $id)->count()); // cascade
    }

    public function test_project_lists_load_services_in_one_query(): void
    {
        foreach (range(1, 2) as $i) {
            $this->actingAs($this->u['client'])->postJson('/api/agency/projects', $this->body(['title' => "P{$i}", 'estimated_hours' => 1, 'services' => $this->services()]))->assertCreated();
        }
        foreach ([['client', '/api/agency/projects'], ['admin', '/api/admin/projects'], ['employee', '/api/work-items/mine']] as [$who, $url]) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->u[$who])->getJson($url)->assertOk();
            $n = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '"project_services"')));
            $this->assertSame(1, $n, "$url loads services with one query");
        }
    }
}