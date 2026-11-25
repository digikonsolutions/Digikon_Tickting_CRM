<?php

namespace App\Services\Attachments;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AttachmentService
{
    public function __construct(private AttachmentScanner $scanner) {}

    /** The private disk uploads live on. The public disk is refused outright. */
    public function disk(): string
    {
        $disk = (string) config('attachments.disk', 'local');
        if ($disk === 'public') {
            throw new RuntimeException('Attachments must never be stored on the public disk.');
        }

        return $disk;
    }

    /** @return array<string, list<string>> extension => allowed content MIME types */
    public function allowedTypes(?bool $archives = null): array
    {
        $types = config('attachments.allowed', []);

        return ($archives ?? config('attachments.allow_archives')) ? $types + config('attachments.archives', []) : $types;
    }

    /** Safe text for display and for Content-Disposition: no paths, control characters or markup. */
    public static function sanitizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = preg_replace('/[^\p{L}\p{N}\.\-_ ()\[\]]/u', '_', $name) ?? '';
        $name = trim(preg_replace('/\.{2,}/', '.', $name) ?? '', " .");

        return $name === '' ? 'file' : Str::limit($name, 150, '');
    }

    /**
     * Checks a file against the configured rules. Returns an error message, or null when acceptable.
     * The MIME type is detected from the file CONTENT, never trusted from the client.
     */
    public function problem(UploadedFile $file, ?bool $archives = null): ?string
    {
        $name = self::sanitizeName((string) $file->getClientOriginalName());

        if (! $file->isValid()) {
            return "{$name} could not be uploaded.";
        }
        if ($file->getSize() === 0) {
            return "{$name} is empty.";
        }
        $ext = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $maxKb = in_array($ext, (array) config('attachments.video_extensions', []), true)
            ? (int) config('attachments.max_video_kb', 102400)
            : (int) config('attachments.max_file_kb', 20480);
        if ($file->getSize() > $maxKb * 1024) {
            return "{$name} is larger than ".round($maxKb / 1024, 1).' MB.';
        }

        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $allowed = $this->allowedTypes($archives);
        if ($extension === '' || ! isset($allowed[$extension])) {
            return "{$name}: files of this type are not allowed.";
        }

        $detected = $this->detectMime($file->getRealPath());
        if (! in_array($detected, $allowed[$extension], true)) {
            return "{$name}: the file content does not match its .{$extension} extension.";
        }
        if (! $this->scanner->isClean($file)) {
            return "{$name} was rejected by the security scan.";
        }

        return null;
    }

    public function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->file($path);
    }

    public function store(Ticket $ticket, User $uploader, UploadedFile $file, bool $internal, ?int $commentEventId): TicketAttachment
    {
        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $disk = $this->disk();
        // Random name in a per-ticket folder: the user's filename never touches the filesystem.
        $path = Storage::disk($disk)->putFileAs("tickets/{$ticket->id}", $file, (string) Str::uuid().'.'.$extension);
        if ($path === false) {
            throw new RuntimeException('The file could not be stored.');
        }

        return TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'comment_event_id' => $commentEventId,
            'uploaded_by' => $uploader->id,
            'uploader_name' => $uploader->name,
            'original_name' => self::sanitizeName((string) $file->getClientOriginalName()),
            'stored_path' => $path,
            'disk' => $disk,
            'mime_type' => $this->detectMime($file->getRealPath()),
            'size_bytes' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'is_internal' => $internal,
        ]);
    }

    /** Stores a file on a project brief (polymorphic ttachments table). */
    public function storeForProject(WorkItem $project, User $uploader, UploadedFile $file): Attachment
    {
        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $disk = $this->disk();
        $path = Storage::disk($disk)->putFileAs("projects/{$project->id}", $file, (string) Str::uuid().'.'.$extension);
        if ($path === false) {
            throw new RuntimeException('The file could not be stored.');
        }

        return $project->attachments()->create([
            'uploaded_by' => $uploader->id,
            'uploader_name' => $uploader->name,
            'original_name' => self::sanitizeName((string) $file->getClientOriginalName()),
            'path' => $path,
            'disk' => $disk,
            'mime' => $this->detectMime($file->getRealPath()),
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);
    }

    /** Stores a file on an agency request (more hours, an employee, ...). */
    public function storeForRequest(\App\Models\AgencyRequest $request, User $uploader, UploadedFile $file): Attachment
    {
        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $disk = $this->disk();
        $path = Storage::disk($disk)->putFileAs("requests/{$request->id}", $file, (string) Str::uuid().'.'.$extension);
        if ($path === false) {
            throw new RuntimeException('The file could not be stored.');
        }

        return $request->attachments()->create([
            'uploaded_by' => $uploader->id,
            'uploader_name' => $uploader->name,
            'original_name' => self::sanitizeName((string) $file->getClientOriginalName()),
            'path' => $path,
            'disk' => $disk,
            'mime' => $this->detectMime($file->getRealPath()),
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);
    }

    public function isInlineSafe(TicketAttachment|Attachment $attachment): bool
    {
        $mime = $attachment instanceof Attachment ? $attachment->mime : $attachment->mime_type;

        return in_array($mime, config('attachments.inline_mimes', []), true);
    }
}
