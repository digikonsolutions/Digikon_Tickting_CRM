<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Services\Attachments\AttachmentPresenter;
use App\Services\Attachments\AttachmentService;
use App\Services\TicketAccess;
use App\Services\TicketEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AttachmentController extends Controller
{
    public function __construct(
        private TicketAccess $access,
        private AttachmentService $attachments,
        private AttachmentPresenter $presenter,
        private TicketEventRecorder $recorder,
    ) {}

    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        $this->access->authorizeView($user, $ticket);

        $items = $ticket->attachments()
            ->when(! $this->access->canSeeInternal($user, $ticket), fn ($query) => $query->where('is_internal', false))
            ->orderBy('id')
            ->get()
            ->map(fn (TicketAttachment $attachment): array => $this->presenter->payload($attachment, $user, $ticket))
            ->values();

        return response()->json(['attachments' => $items, 'limits' => $this->limits()]);
    }

    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        $this->access->authorizeView($user, $ticket);

        $data = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file'],
            'comment' => ['nullable', 'string', 'max:10000'],
            'is_internal' => ['sometimes', 'boolean'],
        ]);
        abort_if(in_array($ticket->status, ['done', 'cancelled'], true), 422, 'Files cannot be added to a done or cancelled ticket.');

        $files = $request->file('files');
        $perUpload = (int) config('attachments.max_files_per_upload', 10);
        $perTicket = (int) config('attachments.max_files_per_ticket', 50);
        if (count($files) > $perUpload) {
            throw ValidationException::withMessages(['files' => "You can upload at most {$perUpload} files at a time."]);
        }
        if ($ticket->attachments()->count() + count($files) > $perTicket) {
            throw ValidationException::withMessages(['files' => "A ticket can hold at most {$perTicket} files."]);
        }

        $errors = [];
        foreach (array_values($files) as $index => $file) {
            $problem = $file instanceof UploadedFile ? $this->attachments->problem($file) : 'Invalid upload.';
            if ($problem !== null) {
                $errors["files.{$index}"] = [$problem];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $comment = trim((string) ($data['comment'] ?? ''));
        $internal = (bool) ($data['is_internal'] ?? false);
        if ($internal) {
            abort_unless(in_array($user->role, [Role::Admin, Role::Employee], true), 403, 'Only employees and administrators can add internal notes.');
            abort_if($comment === '', 422, 'An internal upload needs a note.');
        }

        $stored = [];
        try {
            $result = DB::transaction(function () use ($ticket, $user, $files, $comment, $internal, &$stored) {
                $commentEventId = null;
                if ($comment !== '') {
                    $created = TicketComment::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => $user->id,
                        'body' => $comment,
                        'is_internal' => $internal,
                    ]);
                    $commentEventId = $this->recorder->record(
                        $ticket,
                        'comment',
                        ['comment_id' => $created->id],
                        $user,
                        $internal ? 'internal' : 'public'
                    )->id;
                }

                $rows = [];
                foreach (array_values($files) as $file) {
                    $row = $this->attachments->store($ticket, $user, $file, $internal, $commentEventId);
                    $stored[] = $row;
                    $rows[] = $row;
                }

                // Internal uploads produce internal events so file names never reach agency users.
                $this->recorder->record($ticket, 'attachment_added', [
                    'with_comment' => $commentEventId !== null,
                    'files' => collect($rows)->map(fn (TicketAttachment $a): array => [
                        'id' => $a->id, 'name' => $a->original_name, 'size_bytes' => $a->size_bytes, 'mime_type' => $a->mime_type,
                    ])->all(),
                ], $user, $internal ? 'internal' : 'public');

                return $rows;
            });
        } catch (\Throwable $exception) {
            // Do not leave orphaned files behind if the database work failed.
            foreach ($stored as $row) {
                Storage::disk($row->disk)->delete($row->stored_path);
            }
            throw $exception;
        }

        return response()->json([
            'attachments' => collect($result)->map(fn (TicketAttachment $a): array => $this->presenter->payload($a, $user, $ticket))->values(),
        ], 201);
    }

    public function download(Request $request, TicketAttachment $attachment): Response
    {
        $user = $request->user();
        $ticket = $attachment->ticket;
        $this->access->authorizeView($user, $ticket);
        // Internal files do not exist as far as agency users are concerned.
        abort_if($attachment->is_internal && ! $this->access->canSeeInternal($user, $ticket), 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->stored_path), 404);

        $inline = $request->boolean('inline') && $this->attachments->isInlineSafe($attachment);
        $headers = [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; sandbox",
        ];

        if (config('attachments.temporary_urls') && $disk->providesTemporaryUrls()) {
            return redirect()->away($disk->temporaryUrl(
                $attachment->stored_path,
                now()->addMinutes((int) config('attachments.temporary_url_minutes', 5)),
                ['ResponseContentDisposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$attachment->original_name.'"']
            ));
        }

        return $disk->response($attachment->stored_path, $attachment->original_name, $headers, $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, TicketAttachment $attachment): JsonResponse
    {
        $user = $request->user();
        $ticket = $attachment->ticket;
        $this->access->authorizeView($user, $ticket);
        abort_if($attachment->is_internal && ! $this->access->canSeeInternal($user, $ticket), 404);

        // The uploader, an admin, or the owner of the ticket's agency may remove a file.
        $allowed = $attachment->uploaded_by === $user->id
            || $user->role === Role::Admin
            || $this->access->isAgencyOwner($user, $ticket);
        abort_unless($allowed, 403, 'You cannot remove this file.');

        DB::transaction(function () use ($attachment, $ticket, $user): void {
            $attachment->delete();
            $this->recorder->record($ticket, 'attachment_removed', [
                'attachment_id' => $attachment->id,
                'name' => $attachment->original_name,
            ], $user, $attachment->is_internal ? 'internal' : 'public');
        });

        return response()->json(['message' => 'The file was removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function limits(): array
    {
        return [
            'max_file_kb' => (int) config('attachments.max_file_kb'),
            'max_files_per_upload' => (int) config('attachments.max_files_per_upload'),
            'max_files_per_ticket' => (int) config('attachments.max_files_per_ticket'),
            'extensions' => array_keys($this->attachments->allowedTypes()),
        ];
    }
}
