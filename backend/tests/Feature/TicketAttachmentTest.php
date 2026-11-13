<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTicketFixture;
use Tests\TestCase;

class TicketAttachmentTest extends TestCase
{
    use BuildsTicketFixture;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildFixture();
        $this->ticket = $this->makeTicket('other');
    }

    private function png(string $name = 'shot.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function pdf(string $name = 'brief.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    private function txt(string $name = 'notes.txt', string $body = 'plain notes'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    /** @param list<UploadedFile> $files */
    private function upload(string $by, array $files, array $extra = [], ?Ticket $ticket = null)
    {
        return $this->actingAs($this->u[$by])->post('/api/tickets/'.($ticket ?? $this->ticket)->id.'/attachments', ['files' => $files, ...$extra], ['Accept' => 'application/json']);
    }

    public function test_upload_list_download_and_delete_round_trip(): void
    {
        $response = $this->upload('client', [$this->png(), $this->pdf()])->assertCreated();
        $response->assertJsonCount(2, 'attachments')
            ->assertJsonPath('attachments.0.name', 'shot.png')
            ->assertJsonPath('attachments.0.is_image', true)
            ->assertJsonPath('attachments.1.is_image', false)
            ->assertJsonPath('attachments.0.uploader.name', $this->u['client']->name);
        $id = $response->json('attachments.0.id');

        $this->getJson('/api/tickets/'.$this->ticket->id.'/attachments')->assertOk()->assertJsonCount(2, 'attachments')
            ->assertJsonPath('limits.max_files_per_upload', 10)
            ->assertJsonMissingPath('attachments.0.stored_path');

        foreach (['client', 'owner', 'employee', 'admin'] as $viewer) {
            $this->actingAs($this->u[$viewer])->get('/api/attachments/'.$id.'/download')->assertOk();
        }

        // The server tells each viewer whether they may delete (uploader, admin, agency owner).
        foreach (['client' => true, 'owner' => true, 'admin' => true, 'other' => false, 'employee' => false] as $viewer => $can) {
            $this->actingAs($this->u[$viewer])->getJson('/api/tickets/'.$this->ticket->id.'/attachments')->assertJsonPath('attachments.0.can_delete', $can);
        }
        $this->actingAs($this->u['client']);

        $this->actingAs($this->u['client'])->deleteJson('/api/attachments/'.$id)->assertOk();
        $this->getJson('/api/tickets/'.$this->ticket->id.'/attachments')->assertJsonCount(1, 'attachments');
        $this->get('/api/attachments/'.$id.'/download')->assertNotFound();
    }

    public function test_files_are_stored_privately_under_random_names_and_never_with_the_users_filename(): void
    {
        $this->upload('client', [$this->txt('../../etc/passwd.txt')])->assertCreated();

        $row = TicketAttachment::firstOrFail();
        $this->assertMatchesRegularExpression('#^tickets/'.$this->ticket->id.'/[0-9a-f-]{36}\.txt$#', $row->stored_path);
        $this->assertStringNotContainsString('passwd', $row->stored_path);
        $this->assertSame('passwd.txt', $row->original_name);
        $this->assertNotSame('public', $row->disk);
        Storage::disk('local')->assertExists($row->stored_path);
        $this->assertSame(hash('sha256', 'plain notes'), $row->sha256);
        // Nothing was written to the public disk (only its tracked .gitignore exists).
        $this->assertSame([], array_values(array_diff(Storage::disk('public')->allFiles(), ['.gitignore'])));
    }

    public function test_the_public_disk_is_refused(): void
    {
        config(['attachments.disk' => 'public']);

        $this->expectException(\RuntimeException::class);
        app(\App\Services\Attachments\AttachmentService::class)->disk();
    }

    public function test_downloads_carry_attachment_and_nosniff_headers_and_inline_is_images_only(): void
    {
        $ids = $this->upload('client', [$this->png(), $this->pdf(), $this->txt()])->assertCreated()->json('attachments.*.id');
        $this->actingAs($this->u['client']);

        $png = $this->get('/api/attachments/'.$ids[0].'/download');
        $this->assertStringStartsWith('attachment;', $png->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $png->headers->get('X-Content-Type-Options'));
        $this->assertSame('image/png', $png->headers->get('Content-Type'));
        $this->assertStringContainsString('filename=shot.png', $png->headers->get('Content-Disposition'));

        $inline = $this->get('/api/attachments/'.$ids[0].'/download?inline=1');
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $inline->headers->get('X-Content-Type-Options'));

        // inline=1 is ignored for anything that is not a safe image type.
        foreach ([$ids[1], $ids[2]] as $id) {
            $response = $this->get('/api/attachments/'.$id.'/download?inline=1');
            $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
    }

    public function test_spoofed_mime_svg_and_other_disallowed_types_are_rejected(): void
    {
        // PNG bytes named .pdf, HTML named .png, and a script-carrying svg.
        $this->upload('client', [UploadedFile::fake()->createWithContent('fake.pdf', base64_decode(self::PNG))])
            ->assertUnprocessable()->assertJsonValidationErrors(['files.0']);
        $this->upload('client', [UploadedFile::fake()->createWithContent('page.png', '<html><script>alert(1)</script></html>')])
            ->assertUnprocessable()->assertJsonValidationErrors(['files.0']);
        $this->upload('client', [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])
            ->assertUnprocessable();
        $this->assertSame('logo.svg: files of this type are not allowed.', $this->post('/api/tickets/'.$this->ticket->id.'/attachments', ['files' => [UploadedFile::fake()->createWithContent('logo.svg', '<svg/>')]], ['Accept' => 'application/json'])->json('errors')['files.0'][0]);
        foreach (['run.php', 'evil.html', 'app.js', 'tool.exe', 'noextension'] as $name) {
            $this->upload('client', [$this->txt($name, 'x = 1')])->assertUnprocessable();
        }
        $this->upload('client', [UploadedFile::fake()->createWithContent('bundle.zip', "PK\x03\x04".str_repeat("\0", 30))])->assertUnprocessable();
        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_archives_can_be_enabled_by_config(): void
    {
        config(['attachments.allow_archives' => true]);
        // A minimal empty zip archive (end-of-central-directory record only).
        $zip = UploadedFile::fake()->createWithContent('bundle.zip', "PK\x05\x06".str_repeat("\0", 18));
        $detected = app(\App\Services\Attachments\AttachmentService::class)->detectMime($zip->getRealPath());

        $this->assertContains($detected, ['application/zip', 'application/x-zip-compressed']);
        $this->upload('client', [$zip])->assertCreated();
    }

    public function test_oversize_empty_and_too_many_files_are_rejected_with_clear_messages(): void
    {
        config(['attachments.max_file_kb' => 1]);
        $this->assertSame('big.txt is larger than 0 MB.', $this->upload('client', [$this->txt('big.txt', str_repeat('a', 2048))])->assertUnprocessable()->json('errors')['files.0'][0]);
        config(['attachments.max_file_kb' => 10240]);

        $this->assertSame('empty.txt is empty.', $this->upload('client', [UploadedFile::fake()->createWithContent('empty.txt', '')])->assertUnprocessable()->json('errors')['files.0'][0]);

        config(['attachments.max_files_per_upload' => 2]);
        $this->upload('client', [$this->txt('a.txt'), $this->txt('b.txt'), $this->txt('c.txt')])
            ->assertUnprocessable()->assertJsonPath('errors.files.0', 'You can upload at most 2 files at a time.');

        config(['attachments.max_files_per_upload' => 10, 'attachments.max_files_per_ticket' => 3]);
        $this->upload('client', [$this->txt('a.txt'), $this->txt('b.txt'), $this->txt('c.txt')])->assertCreated();
        $this->upload('client', [$this->txt('d.txt')])->assertUnprocessable()->assertJsonPath('errors.files.0', 'A ticket can hold at most 3 files.');
        $this->upload('client', [])->assertUnprocessable();
        $this->assertSame(3, TicketAttachment::count());
    }

    public function test_file_names_are_sanitised_for_storage_display_and_headers(): void
    {
        $id = $this->upload('client', [$this->txt("rep\"ort\r\nX-Evil: 1<script>.txt")])->assertCreated()->json('attachments.0.id');

        $row = TicketAttachment::findOrFail($id);
        $this->assertSame('rep_ortX-Evil_ 1_script_.txt', $row->original_name);
        $disposition = $this->actingAs($this->u['client'])->get('/api/attachments/'.$id.'/download')->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString('<script>', $disposition);
        $this->assertSame('file', \App\Services\Attachments\AttachmentService::sanitizeName('../..//'));
    }

    public function test_cross_agency_users_roles_and_guests_are_denied(): void
    {
        $id = $this->upload('client', [$this->png()])->assertCreated()->json('attachments.0.id');
        $outsider = $this->outsider();
        $unrelatedEmployee = \App\Models\User::factory()->create(['role' => \App\Enums\Role::Employee]);

        $this->post('/api/tickets/'.$this->ticket->id.'/attachments', [], ['Accept' => 'application/json']); // keeps session
        auth()->logout();
        $this->getJson('/api/tickets/'.$this->ticket->id.'/attachments')->assertUnauthorized();
        $this->get('/api/attachments/'.$id.'/download', ['Accept' => 'application/json'])->assertUnauthorized();
        $this->deleteJson('/api/attachments/'.$id)->assertUnauthorized();

        foreach ([$outsider, $unrelatedEmployee] as $stranger) {
            $this->actingAs($stranger)->getJson('/api/tickets/'.$this->ticket->id.'/attachments')->assertNotFound();
            $this->get('/api/attachments/'.$id.'/download')->assertNotFound();
            $this->deleteJson('/api/attachments/'.$id)->assertNotFound();
            $this->post('/api/tickets/'.$this->ticket->id.'/attachments', ['files' => [$this->txt()]], ['Accept' => 'application/json'])->assertNotFound();
        }
        $this->assertSame(1, TicketAttachment::count());
    }

    public function test_only_the_uploader_an_admin_or_the_agency_owner_may_delete(): void
    {
        $id = $this->upload('client', [$this->png()])->assertCreated()->json('attachments.0.id');

        $this->actingAs($this->u['other'])->deleteJson('/api/attachments/'.$id)->assertForbidden();
        $this->actingAs($this->u['employee'])->deleteJson('/api/attachments/'.$id)->assertForbidden();
        $this->assertNull(TicketAttachment::findOrFail($id)->deleted_at);

        $this->actingAs($this->u['owner'])->deleteJson('/api/attachments/'.$id)->assertOk();
        $second = $this->upload('client', [$this->png('two.png')])->json('attachments.0.id');
        $this->actingAs($this->u['admin'])->deleteJson('/api/attachments/'.$second)->assertOk();
        $third = $this->upload('employee', [$this->png('three.png')])->json('attachments.0.id');
        $this->actingAs($this->u['employee'])->deleteJson('/api/attachments/'.$third)->assertOk();
    }

    public function test_soft_delete_keeps_the_row_and_the_file_and_records_an_event(): void
    {
        $id = $this->upload('client', [$this->png()])->json('attachments.0.id');
        $path = TicketAttachment::findOrFail($id)->stored_path;

        $this->actingAs($this->u['client'])->deleteJson('/api/attachments/'.$id)->assertOk();

        $this->assertSoftDeleted('ticket_attachments', ['id' => $id]);
        Storage::disk('local')->assertExists($path);
        $event = TicketEvent::where('ticket_id', $this->ticket->id)->where('type', 'attachment_removed')->firstOrFail();
        $this->assertSame($id, $event->payload['attachment_id']);
        $this->assertSame('public', $event->visibility);
        $this->assertSame($this->u['client']->name, $event->actor_name);
    }

    public function test_upload_creates_an_attachment_added_event_and_a_comment_when_text_is_given(): void
    {
        $this->upload('client', [$this->png(), $this->pdf()])->assertCreated();
        $event = TicketEvent::where('type', 'attachment_added')->firstOrFail();
        $this->assertSame('public', $event->visibility);
        $this->assertCount(2, $event->payload['files']);
        $this->assertFalse($event->payload['with_comment']);

        $this->upload('client', [$this->txt('spec.txt')], ['comment' => "See the attached spec\nthanks"])->assertCreated();
        $commentEvent = TicketEvent::where('type', 'comment')->latest('id')->firstOrFail();
        $this->assertSame('See the attached spec'."\n".'thanks', \App\Models\TicketComment::findOrFail($commentEvent->payload['comment_id'])->body);
        $this->assertSame($commentEvent->id, TicketAttachment::where('original_name', 'spec.txt')->value('comment_event_id'));

        $timeline = $this->getJson('/api/tickets/'.$this->ticket->id.'/timeline')->assertOk()->json('events');
        $withFiles = collect($timeline)->firstWhere('id', $commentEvent->id);
        $this->assertSame(['spec.txt'], collect($withFiles['attachments'])->pluck('name')->all());
    }

    public function test_internal_attachments_are_hidden_from_agency_users_everywhere(): void
    {
        $response = $this->upload('employee', [$this->txt('secret-plan.txt')], ['comment' => 'Internal notes attached', 'is_internal' => true])->assertCreated();
        $id = $response->json('attachments.0.id');
        $response->assertJsonPath('attachments.0.is_internal', true);
        $event = TicketEvent::where('type', 'attachment_added')->firstOrFail();
        $this->assertSame('internal', $event->visibility);

        foreach (['employee', 'admin'] as $viewer) {
            $this->actingAs($this->u[$viewer])->getJson('/api/tickets/'.$this->ticket->id.'/attachments')->assertJsonCount(1, 'attachments');
            $this->get('/api/attachments/'.$id.'/download')->assertOk();
        }
        foreach (['client', 'other', 'owner'] as $viewer) {
            $this->actingAs($this->u[$viewer])->getJson('/api/tickets/'.$this->ticket->id.'/attachments')->assertJsonCount(0, 'attachments');
            $this->get('/api/attachments/'.$id.'/download')->assertNotFound();
            $this->deleteJson('/api/attachments/'.$id)->assertNotFound();
            $timeline = $this->getJson('/api/tickets/'.$this->ticket->id.'/timeline')->assertOk()->getContent();
            $this->assertStringNotContainsString('secret-plan', $timeline);
        }
        // Agency users cannot create internal uploads either.
        $this->upload('client', [$this->txt()], ['comment' => 'x', 'is_internal' => true])->assertForbidden();
        $this->upload('employee', [$this->txt()], ['is_internal' => true])->assertUnprocessable();
    }

    public function test_uploads_to_done_or_cancelled_tickets_are_rejected_but_downloads_stay_allowed(): void
    {
        $id = $this->upload('client', [$this->png()])->json('attachments.0.id');
        $cancelled = $this->makeTicket('other', 'Cancelled one');
        $this->actingAs($this->u['client'])->deleteJson('/api/agency/tickets/'.$cancelled->id)->assertOk();
        $this->transition('employee', $this->ticket, 'start');
        $this->transition('employee', $this->ticket, 'send_to_review');
        $this->transition('owner', $this->ticket, 'approve');

        $this->upload('client', [$this->txt()])->assertUnprocessable()->assertJsonPath('message', 'Files cannot be added to a done or cancelled ticket.');
        $this->actingAs($this->u['client'])->get('/api/attachments/'.$id.'/download')->assertOk();

        $this->upload('client', [$this->txt()], [], $cancelled)->assertUnprocessable();
    }

    public function test_attachment_queries_do_not_grow_with_the_number_of_files(): void
    {
        $this->upload('client', [$this->txt('a.txt'), $this->txt('b.txt')]);
        $this->actingAs($this->u['client']);
        $measure = function (): int {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->getJson('/api/tickets/'.$this->ticket->id.'/attachments')->assertOk();
            $count = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return $count;
        };
        $this->getJson('/api/tickets/'.$this->ticket->id.'/attachments');
        $before = $measure();
        $this->upload('client', array_map(fn ($n) => $this->txt("f{$n}.txt"), range(1, 8)));
        $this->assertSame($before, $measure());
    }
}
