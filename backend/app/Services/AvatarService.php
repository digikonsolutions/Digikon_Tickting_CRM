<?php

namespace App\Services;

use App\Models\User;
use App\Services\Attachments\AttachmentScanner;
use App\Services\Attachments\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Profile photo upload. Unlike ticket/project attachments, an avatar lives on the PUBLIC disk (it is
 * shown in the header, sidebar and employee timeline, so it must have a plain URL) and is always an
 * image, so this is a small service of its own rather than a special case in AttachmentService.
 */
class AvatarService
{
    public function __construct(private AttachmentScanner $scanner)
    {
    }

    /** JPG, JPEG, PNG and WEBP only; content is sniffed and must match the extension. */
    public function problem(UploadedFile $file): ?string
    {
        $name = AttachmentService::sanitizeName((string) $file->getClientOriginalName());

        if (! $file->isValid()) {
            return "{$name} could not be uploaded.";
        }
        if ($file->getSize() === 0) {
            return "{$name} is empty.";
        }
        $maxKb = (int) config('attachments.avatar_max_kb', 4096);
        if ($file->getSize() > $maxKb * 1024) {
            return "{$name} is larger than ".round($maxKb / 1024, 1).' MB.';
        }

        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $allowed = (array) config('attachments.avatar_allowed', []);
        if ($extension === '' || ! isset($allowed[$extension])) {
            return "{$name}: please upload a JPG, PNG or WEBP image.";
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = (string) $finfo->file((string) $file->getRealPath());
        if (! in_array($detected, $allowed[$extension], true)) {
            return "{$name}: the file content does not match its .{$extension} extension.";
        }
        if (! $this->scanner->isClean($file)) {
            return "{$name} was rejected by the security scan.";
        }

        return null;
    }

    /** Stores the new photo and removes the previous one. Does not touch any other profile field. */
    public function store(User $user, UploadedFile $file): string
    {
        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $path = Storage::disk('public')->putFileAs('avatars', $file, (string) Str::uuid().'.'.$extension);
        if ($path === false) {
            throw new RuntimeException('The photo could not be stored.');
        }

        $this->delete($user);
        $user->forceFill(['photo_path' => $path])->save();

        return $path;
    }

    public function delete(User $user): void
    {
        if ($user->photo_path) {
            Storage::disk('public')->delete($user->photo_path);
        }
    }

    public function remove(User $user): void
    {
        $this->delete($user);
        $user->forceFill(['photo_path' => null])->save();
    }
}
