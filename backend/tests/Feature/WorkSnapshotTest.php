<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\WorkSnapshot;
use App\Services\MonitoringPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

/** Work snapshots: off by default, consent-gated, only while a ticket is in progress, only on approved sites. */
class WorkSnapshotTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'activity.snapshots.enabled' => true, 'monitoring.version' => '3.0',
            'monitoring.categories.work_snapshots' => ['label' => 'Work snapshots', 'description' => 'A small picture of the tab in front.'],
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-12-09 10:00', config('app.timezone')));
        $this->buildFixture();
        app(MonitoringPolicy::class)->accept($this->u['employee'], null);
        $this->ticket = Ticket::create([
            'agency_id' => $this->agency->id, 'work_item_id' => $this->project->id, 'created_by' => $this->u['client']->id, 'number' => 950,
            'title' => 'Build header', 'description' => 'x', 'estimated_hours' => 2, 'priority' => 'normal', 'status' => 'in_progress', 'position' => 1,
        ]);
    }

    /** A tiny real JPEG that reports `$kb` kilobytes. The test PHP has no GD, so images are not generated. */
    private function jpeg(int $kb): UploadedFile
    {
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        return UploadedFile::fake()->createWithContent('tab.jpg', $bytes)->size($kb);
    }

    private function upload(array $extra = [], string $who = 'employee')
    {
        return $this->actingAs($this->u[$who])->post('/api/browser-history/snapshots', [
            'image' => $this->jpeg(80), 'domain' => 'app.gohighlevel.com', 'title' => 'Workflows',
            'captured_at' => now()->toIso8601String(), 'width' => 800, 'height' => 500, ...$extra,
        ], ['Accept' => 'application/json']);
    }

    public function test_a_snapshot_is_stored_and_linked_to_the_ticket_in_progress(): void
    {
        $this->upload()->assertCreated();
        $row = WorkSnapshot::first();
        $this->assertSame($this->ticket->id, $row->ticket_id);
        $this->assertSame($this->project->id, $row->work_item_id);
        $this->assertSame('app.gohighlevel.com', $row->domain);
        Storage::disk('local')->assertExists($row->path);
    }

    public function test_nothing_happens_while_the_feature_is_switched_off(): void
    {
        config(['activity.snapshots.enabled' => false]);
        $this->upload()->assertNotFound();
        $this->assertSame(0, WorkSnapshot::count());
        $status = $this->actingAs($this->u['employee'])->getJson('/api/browser-history/status')->assertOk();
        $this->assertNull($status->json('snapshots'));
    }

    public function test_the_current_notice_must_be_accepted_first(): void
    {
        config(['monitoring.version' => '3.1']);
        $this->upload()->assertForbidden();
        $this->assertSame(0, WorkSnapshot::count());
    }

    public function test_no_snapshot_is_kept_without_a_ticket_in_progress(): void
    {
        $this->ticket->update(['status' => 'in_review']);
        $this->upload()->assertStatus(409);
        $this->assertSame(0, WorkSnapshot::count());
    }

    public function test_only_approved_sites_and_their_subdomains_are_kept(): void
    {
        $this->upload(['domain' => 'mail.example.com'])->assertStatus(422);
        $this->upload(['domain' => 'evilgohighlevel.com'])->assertStatus(422);
        $this->upload(['domain' => 'GoHighLevel.com'])->assertCreated();
    }

    public function test_pictures_must_be_images_and_small_and_recent_and_spaced_out(): void
    {
        $this->upload(['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertStatus(422);
        $this->upload(['image' => $this->jpeg(900)])->assertStatus(422);
        $this->upload(['captured_at' => now()->subHours(2)->toIso8601String()])->assertStatus(422);
        $this->upload()->assertCreated();
        $this->upload()->assertStatus(429);
        $this->assertSame(1, WorkSnapshot::count());
    }

    public function test_only_the_employee_uploads_and_the_status_says_when_to_take_them(): void
    {
        foreach (['owner', 'client', 'admin'] as $who) {
            $this->upload([], $who)->assertForbidden();
        }
        $res = $this->actingAs($this->u['employee'])->getJson('/api/browser-history/status')->assertOk();
        $this->assertTrue($res->json('snapshots.enabled'));
        $this->assertContains('gohighlevel.com', $res->json('snapshots.allowed_domains'));
        $this->ticket->update(['status' => 'in_review']);
        $this->assertNull($this->actingAs($this->u['employee'])->getJson('/api/browser-history/status')->json('snapshots'));
    }

    public function test_admins_read_the_pictures_and_every_view_is_audited_but_nobody_else_can(): void
    {
        $this->upload()->assertCreated();
        $id = WorkSnapshot::first()->id;
        $list = $this->actingAs($this->u['admin'])->getJson('/api/admin/browser-history/snapshots')->assertOk();
        $list->assertJsonPath('snapshots.0.ticket.key', $this->ticket->key)->assertJsonPath('snapshots.0.employee.name', $this->u['employee']->name);

        $image = $this->actingAs($this->u['admin'])->get('/api/admin/browser-history/snapshots/'.$id.'/image')->assertOk();
        $this->assertStringContainsString('no-store', (string) $image->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'viewed_snapshot', 'subject_id' => $id, 'user_id' => $this->u['admin']->id]);

        foreach (['employee', 'owner', 'client'] as $who) {
            $this->actingAs($this->u[$who])->getJson('/api/admin/browser-history/snapshots')->assertForbidden();
            $this->actingAs($this->u[$who])->get('/api/admin/browser-history/snapshots/'.$id.'/image')->assertForbidden();
        }
    }

    public function test_the_employee_can_see_what_was_taken_but_not_other_peoples(): void
    {
        $this->upload()->assertCreated();
        $mine = $this->actingAs($this->u['employee'])->getJson('/api/browser-history/snapshots/me')->assertOk();
        $mine->assertJsonPath('today', 1)->assertJsonPath('snapshots.0.ticket_key', $this->ticket->key);
        $this->assertArrayNotHasKey('image_url', $mine->json('snapshots.0'));
    }

    public function test_old_snapshots_are_deleted_with_their_files(): void
    {
        $this->upload()->assertCreated();
        $row = WorkSnapshot::first();
        $row->forceFill(['captured_at' => now()->subDays(40)])->save();
        $this->artisan('snapshots:prune')->assertSuccessful();
        $this->assertSame(0, WorkSnapshot::count());
        Storage::disk('local')->assertMissing($row->path);
    }
}