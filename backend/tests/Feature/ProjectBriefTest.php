<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\Attachment;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

class ProjectBriefTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildFixture();
    }

    private function brief(array $overrides = []): array
    {
        return [
            'objective' => 'Launch a new marketing site',
            'deliverables' => ['Homepage', 'Pricing page'],
            'target_url' => 'https://example.com',
            'staging_url' => 'https://staging.example.com',
            'repo_url' => 'https://github.com/acme/site',
            'platform' => 'wordpress',
            'tech_stack' => 'WordPress, Elementor',
            'brand_assets_url' => 'https://drive.example.com/brand',
            'access_details' => 'wp-admin user: acme / password: hunter2-secret',
            'reference_links' => ['https://example.org/inspiration'],
            'acceptance_criteria' => 'Passes Lighthouse 90+',
            'budget' => '$5k',
            'brief_priority' => 'high',
            'contact_name' => 'Pat Doe',
            'contact_email' => 'pat@example.com',
            ...$overrides,
        ];
    }

    private function projectPayload(array $extra = []): array
    {
        $monday = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addWeeks(5);
        $assignment = EmployeeAssignment::where('holder_id', $this->u['client']->id)->first();

        return [
            'title' => 'Brief project', 'description' => 'Details.', 'estimated_hours' => 6,
            'start_date' => $monday->toDateString(), 'due_date' => $monday->addDays(5)->toDateString(),
            'assignment_id' => $assignment->id, ...$extra,
        ];
    }

    private function createProject(array $extra = []): int
    {
        return $this->actingAs($this->u['client'])->postJson('/api/agency/projects', $this->projectPayload($extra))->assertCreated()->json('project.id');
    }

    /* ---- project brief ---------------------------------------------------------------------- */

    public function test_project_brief_is_saved_and_returned(): void
    {
        $id = $this->createProject($this->brief());

        $show = $this->actingAs($this->u['owner'])->getJson("/api/agency/projects/{$id}")->assertOk();
        $show->assertJsonPath('project.brief.objective', 'Launch a new marketing site')
            ->assertJsonPath('project.brief.deliverables', ['Homepage', 'Pricing page'])
            ->assertJsonPath('project.brief.platform', 'wordpress')
            ->assertJsonPath('project.brief.contact_email', 'pat@example.com')
            ->assertJsonPath('project.brief.has_access_details', true)
            ->assertJsonPath('project.brief.access_details', 'wp-admin user: acme / password: hunter2-secret');
    }

    public function test_access_details_are_encrypted_at_rest_and_left_out_of_lists(): void
    {
        $id = $this->createProject($this->brief());

        $raw = DB::table('work_items')->where('id', $id)->value('access_details');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('hunter2', $raw);

        $list = $this->actingAs($this->u['owner'])->getJson('/api/agency/projects')->assertOk();
        $this->assertStringNotContainsString('hunter2', $list->getContent());
        $list->assertJsonPath('projects.0.brief.has_access_details', true);
        $list->assertJsonMissingPath('projects.0.brief.access_details');
    }

    public function test_assigned_employee_and_admin_can_read_access_details_outsiders_cannot(): void
    {
        $id = $this->createProject($this->brief());

        $mine = $this->actingAs($this->u['employee'])->getJson('/api/work-items/mine')->assertOk()->json('projects');
        $this->assertSame('wp-admin user: acme / password: hunter2-secret', collect($mine)->firstWhere('id', $id)['brief']['access_details']);
        $this->actingAs($this->u['admin'])->getJson("/api/admin/projects/{$id}")->assertOk()
            ->assertJsonPath('project.brief.access_details', 'wp-admin user: acme / password: hunter2-secret');
        $this->actingAs($this->outsider())->getJson("/api/agency/projects/{$id}")->assertNotFound();
    }

    public function test_the_brief_is_optional(): void
    {
        $id = $this->createProject();

        $this->actingAs($this->u['client'])->getJson("/api/agency/projects/{$id}")
            ->assertJsonPath('project.brief.objective', null)->assertJsonPath('project.brief.has_access_details', false);
    }

    public function test_project_brief_validation(): void
    {
        $post = fn (array $extra) => $this->actingAs($this->u['client'])->postJson('/api/agency/projects', $this->projectPayload($extra));

        $post(['target_url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('target_url');
        $post(['repo_url' => 'ftp://example.com/repo'])->assertUnprocessable()->assertJsonValidationErrors('repo_url');
        $post(['reference_links' => ['https://ok.example', 'data:text/html,hi']])->assertUnprocessable()->assertJsonValidationErrors('reference_links.1');
        $post(['platform' => 'mainframe'])->assertUnprocessable()->assertJsonValidationErrors('platform');
        $post(['contact_email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('contact_email');
        $post(['deliverables' => array_fill(0, 26, 'x')])->assertUnprocessable()->assertJsonValidationErrors('deliverables');
        $post(['access_details' => str_repeat('a', 3001)])->assertUnprocessable()->assertJsonValidationErrors('access_details');
        $this->assertSame(0, WorkItem::where('title', 'Brief project')->count());
    }

    /* ---- ticket brief ----------------------------------------------------------------------- */

    public function test_ticket_brief_fields_are_saved_updated_and_returned(): void
    {
        $first = $this->makeTicket('other', 'First', 1);
        $response = $this->actingAs($this->u['client'])->postJson('/api/agency/projects/'.$this->project->id.'/tickets', [
            'title' => 'Broken form', 'description' => 'It fails.', 'estimated_hours' => 1,
            'steps_to_reproduce' => "1. Open the form\n2. Submit", 'expected_result' => 'A thank-you page', 'actual_result' => 'A blank page',
            'affected_url' => 'https://example.com/contact', 'environment' => 'Chrome 130 on macOS', 'severity' => 'high',
            'due_preference' => 'this_week', 'related_ticket_ids' => [$first->id],
        ])->assertCreated();
        $response->assertJsonPath('ticket.brief.severity', 'high')->assertJsonPath('ticket.brief.related_ticket_ids', [$first->id]);

        $id = $response->json('ticket.id');
        $this->actingAs($this->u['client'])->patchJson("/api/agency/tickets/{$id}", ['severity' => 'critical', 'actual_result' => 'A 500 error'])
            ->assertOk()->assertJsonPath('ticket.brief.severity', 'critical')->assertJsonPath('ticket.brief.expected_result', 'A thank-you page');

        $key = Ticket::find($id)->key;
        $this->actingAs($this->u['employee'])->getJson("/api/tickets/key/{$key}")->assertOk()
            ->assertJsonPath('related_tickets.0.key', $first->key)->assertJsonPath('ticket.brief.affected_url', 'https://example.com/contact');
    }

    public function test_ticket_brief_validation_and_related_ticket_isolation(): void
    {
        $other = new class
        {
        };
        $agency = Agency::create(['name' => 'Elsewhere', 'ticket_prefix' => 'ELSE']);
        $foreignProject = WorkItem::create([
            'agency_id' => $agency->id, 'client_id' => $this->u['owner']->id, 'assignment_id' => $this->project->assignment_id,
            'employee_id' => $this->u['employee']->id, 'title' => 'Foreign', 'description' => 'x', 'estimated_hours' => 5,
            'start_date' => now()->addDays(40)->toDateString(), 'due_date' => now()->addDays(45)->toDateString(), 'status' => 'scheduled',
        ]);
        $foreign = $foreignProject->tickets()->create(['agency_id' => $agency->id, 'number' => 1, 'created_by' => $this->u['owner']->id, 'title' => 'Foreign ticket', 'description' => 'x', 'estimated_hours' => 1, 'priority' => 'normal', 'status' => 'todo', 'position' => 1]);
        $own = $this->makeTicket('other', 'Own', 1);

        $post = fn (array $extra) => $this->actingAs($this->u['client'])->postJson('/api/agency/projects/'.$this->project->id.'/tickets', ['title' => 'T', 'description' => 'D', 'estimated_hours' => 1, ...$extra]);

        $post(['related_ticket_ids' => [$foreign->id]])->assertUnprocessable()->assertJsonValidationErrors('related_ticket_ids.0');
        $post(['related_ticket_ids' => [999999]])->assertUnprocessable();
        $post(['severity' => 'apocalyptic'])->assertUnprocessable()->assertJsonValidationErrors('severity');
        $post(['affected_url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('affected_url');
        $post(['due_preference' => 'yesterday'])->assertUnprocessable()->assertJsonValidationErrors('due_preference');
        $this->actingAs($this->u['client'])->patchJson("/api/agency/tickets/{$own->id}", ['related_ticket_ids' => [$own->id]])->assertUnprocessable();
        unset($other);
    }

    /* ---- project attachments ---------------------------------------------------------------- */

    private function upload(string $by, int $projectId, array $files)
    {
        return $this->actingAs($this->u[$by])->post("/api/projects/{$projectId}/attachments", ['files' => $files], ['Accept' => 'application/json']);
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function pdf(string $name = 'brief.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    public function test_project_files_upload_list_and_download_with_safe_headers(): void
    {
        $id = $this->createProject();
        $zip = UploadedFile::fake()->createWithContent('assets.zip', "PK\x05\x06".str_repeat("\0", 18));

        $created = $this->upload('client', $id, [$this->png(), $this->pdf(), $zip])->assertCreated();
        $created->assertJsonCount(3, 'attachments')->assertJsonMissingPath('attachments.0.path');
        $first = $created->json('attachments.0.id');

        $this->assertSame(3, Attachment::where('attachable_id', $id)->count());
        $row = Attachment::find($first);
        $this->assertSame(WorkItem::class, $row->attachable_type);
        $this->assertMatchesRegularExpression('#^projects/\d+/[0-9a-f-]{36}\.png$#', $row->path);
        Storage::disk('local')->assertExists($row->path);
        $this->assertEmpty(array_filter(Storage::disk('public')->allFiles(), fn ($file) => str_starts_with($file, 'projects/')));

        foreach (['client', 'owner', 'employee', 'admin'] as $viewer) {
            $response = $this->actingAs($this->u[$viewer])->get('/api/project-attachments/'.$first.'/download')->assertOk();
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        }
        $this->actingAs($this->u['owner'])->getJson("/api/projects/{$id}/attachments")->assertOk()
            ->assertJsonCount(3, 'attachments')->assertJsonPath('can_upload', true);
    }

    public function test_project_file_validation_rejects_spoofed_dangerous_and_oversized_files(): void
    {
        $id = $this->createProject();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $spoof = UploadedFile::fake()->createWithContent('photo.png', '<?php echo "pwned";');
        $php = UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;');
        $big = UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf');

        $this->upload('client', $id, [$svg])->assertUnprocessable();
        $this->upload('client', $id, [$spoof])->assertUnprocessable();
        $this->upload('client', $id, [$php])->assertUnprocessable();
        $this->upload('client', $id, [$big])->assertUnprocessable();
        $this->upload('client', $id, [$this->png(), $svg])->assertUnprocessable();
        $this->assertSame(0, Attachment::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_zip_files_are_refused_when_briefs_do_not_allow_archives(): void
    {
        config(['attachments.brief_allow_archives' => false]);
        $id = $this->createProject();

        $this->upload('client', $id, [UploadedFile::fake()->createWithContent('assets.zip', "PK\x05\x06".str_repeat("\0", 18))])->assertUnprocessable();
    }

    public function test_project_files_are_tenant_isolated_and_role_limited(): void
    {
        $id = $this->createProject();
        $fileId = $this->upload('client', $id, [$this->png()])->assertCreated()->json('attachments.0.id');

        $stranger = $this->outsider();
        $unassigned = User::factory()->create(['role' => Role::Employee]);
        foreach ([$stranger, $unassigned] as $user) {
            $this->actingAs($user)->getJson("/api/projects/{$id}/attachments")->assertNotFound();
            $this->actingAs($user)->get("/api/project-attachments/{$fileId}/download")->assertNotFound();
            $this->actingAs($user)->deleteJson("/api/project-attachments/{$fileId}")->assertNotFound();
            $this->actingAs($user)->post("/api/projects/{$id}/attachments", ['files' => [$this->png('x.png')]], ['Accept' => 'application/json'])->assertNotFound();
        }

        // The assigned employee and admin may look but not change anything.
        foreach (['employee', 'admin'] as $who) {
            $this->upload($who, $id, [$this->png('y.png')])->assertForbidden();
            $this->actingAs($this->u[$who])->deleteJson("/api/project-attachments/{$fileId}")->assertForbidden();
        }
        $this->assertSame(1, Attachment::count());
    }

    public function test_only_the_uploader_or_owner_can_delete_and_closed_projects_refuse_uploads(): void
    {
        $id = $this->createProject();
        $fileId = $this->upload('client', $id, [$this->png()])->assertCreated()->json('attachments.0.id');

        $this->actingAs($this->u['other'])->deleteJson("/api/project-attachments/{$fileId}")->assertForbidden();
        $this->actingAs($this->u['client'])->deleteJson("/api/project-attachments/{$fileId}")->assertOk();
        $this->actingAs($this->u['client'])->get("/api/project-attachments/{$fileId}/download")->assertNotFound();
        $this->assertSoftDeleted('attachments', ['id' => $fileId]);

        $this->actingAs($this->u['client'])->patchJson("/api/agency/projects/{$id}/cancel")->assertOk();
        $this->upload('client', $id, [$this->png('late.png')])->assertUnprocessable();
    }

    public function test_per_project_file_limit(): void
    {
        config(['attachments.max_files_per_project' => 2]);
        $id = $this->createProject();

        $this->upload('client', $id, [$this->png('a.png'), $this->png('b.png')])->assertCreated();
        $this->upload('client', $id, [$this->png('c.png')])->assertUnprocessable()->assertJsonValidationErrors('files');
    }
}
