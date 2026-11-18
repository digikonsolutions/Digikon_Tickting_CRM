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
    /**
     * Validate the uploaded avatar file.
     * Accepts only jpg, png, webp (real mime), max 2 MB, never SVG.
     */
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
    /** Stores the new photo and removes the previous one. Does not touch any other profile field. */
    public function store(User $user, UploadedFile $file): string
    {
        // Resolve Cloudinary uploader via container
        $uploader = app(\App\Services\CloudinaryUploader::class);
        $folder = rtrim(config('cloudinary.root_folder', 'clienthub'), '/') . '/avatars';
        $options = [
            'transformation' => [
                'width' => 512,
                'height' => 512,
                'crop' => 'fill',
                'gravity' => 'face',
            ],
        ];

        // Keep reference to old asset in case we need to rollback
        $oldPublicId = $user->avatar_public_id;
        $oldUrl = $user->avatar_url;

        try {
            $result = $uploader->upload($file, $folder, $options);
        } catch (\Exception $e) {
            // No state change – user keeps existing avatar
            throw $e; // will be handled upstream as 500 with safe message
        }

        // Attempt to persist the new avatar info
        $user->forceFill([
            'avatar_public_id' => $result['public_id'],
            'avatar_url'       => $result['secure_url'],
        ]);

        try {
            $user->save();
        } catch (\Exception $e) {
            // DB save failed – clean up the newly uploaded asset
            $uploader->delete($result['public_id']);
            // Restore original fields (no change needed as they were not saved)
            throw $e;
        }

        // If there was a previous avatar, delete it now (won't affect the new one)
        if ($oldPublicId) {
            $uploader->delete($oldPublicId);
        }

        // Return the public URL for convenience (not used by controller)
        return $result['secure_url'];
    }

    public function delete(User $user): void
    {
        if ($user->avatar_public_id) {
            $uploader = app(\App\Services\CloudinaryUploader::class);
            $uploader->delete($user->avatar_public_id);
        }
    }


    public function remove(User $user): void
    {
        // Delete Cloudinary asset if it exists
        $this->delete($user);
        // Clear DB columns
        $user->forceFill([
            'avatar_public_id' => null,
            'avatar_url' => null,
        ])->save();
    }

}
