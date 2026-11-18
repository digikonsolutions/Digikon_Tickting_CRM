<?php

namespace Tests\Support;

use App\Services\CloudinaryUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** In-memory stand-in so tests never call the real Cloudinary account. Bound in Tests\TestCase. */
class FakeCloudinaryUploader extends CloudinaryUploader
{
    /** @var array<string, array{folder: string, bytes: int}> public_id => asset */
    public array $assets = [];

    /** @var array<string, array<string, mixed>> public_id => the options it was uploaded with */
    public array $options = [];

    /** @var list<string> public ids that were deleted, in order */
    public array $deleted = [];

    public function __construct()
    {
        // No credentials and no SDK client: every method is overridden.
    }

    public function upload(UploadedFile $file, string $folder, array $options = []): array
    {
        $publicId = trim($folder, '/').'/'.Str::lower(Str::random(12));
        $this->assets[$publicId] = ['folder' => $folder, 'bytes' => (int) $file->getSize()];
        $this->options[$publicId] = $options;

        return [
            'public_id' => $publicId,
            'secure_url' => "https://res.cloudinary.test/{$publicId}.png",
            'resource_type' => 'image',
            'format' => 'png',
            'bytes' => (int) $file->getSize(),
            'width' => null,
            'height' => null,
        ];
    }

    public function delete(string $publicId, string $resourceType = 'image'): bool
    {
        unset($this->assets[$publicId]);
        $this->deleted[] = $publicId;

        return true;
    }

    public function signedUrl(string $publicId, string $resourceType = 'image'): string
    {
        return "https://res.cloudinary.test/signed/{$publicId}";
    }

    /** @return list<string> public ids stored under a folder prefix */
    public function under(string $prefix): array
    {
        return array_values(array_filter(array_keys($this->assets), fn (string $id): bool => str_starts_with($id, trim($prefix, '/').'/')));
    }
}
