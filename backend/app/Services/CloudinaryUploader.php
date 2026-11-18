<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CloudinaryUploader
{
    protected Cloudinary $cloudinary;

    public function __construct()
    {
        $url = config('cloudinary.cloud_url') ?: config('cloudinary.url');

        if (! $url) {
            throw new RuntimeException('CLOUDINARY_URL is not configured.');
        }

        // The SDK parses cloudinary://key:secret@cloud_name itself.
        $this->cloudinary = new Cloudinary($url);
    }

    public function upload(UploadedFile $file, string $folder, array $options = []): array
    {
        try {
            $result = $this->cloudinary->uploadApi()->upload($file->getRealPath(), array_merge([
                'folder' => $folder,
                'resource_type' => 'image',
                'use_filename' => true,
                'unique_filename' => true,
                'overwrite' => false,
            ], $options));

            return [
                'public_id'     => $result['public_id'],
                'secure_url'    => $result['secure_url'],
                'resource_type' => $result['resource_type'],
                'format'        => $result['format'] ?? null,
                'bytes'         => $result['bytes'],
                'width'         => $result['width'] ?? null,
                'height'        => $result['height'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('Cloudinary upload failed', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Failed to upload image to Cloudinary.', 0, $e);
        }
    }

    public function delete(string $publicId, string $resourceType = 'image'): bool
    {
        try {
            $this->cloudinary->uploadApi()->destroy($publicId, [
                'resource_type' => $resourceType,
                'invalidate' => true,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Cloudinary delete failed', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'publicId' => $publicId,
            ]);
            throw new RuntimeException('Failed to delete asset from Cloudinary.', 0, $e);
        }
    }

    public function signedUrl(string $publicId, string $resourceType = 'image'): string
    {
        try {
            $asset = match ($resourceType) {
                'video' => $this->cloudinary->video($publicId),
                'raw'   => $this->cloudinary->raw($publicId),
                default => $this->cloudinary->image($publicId),
            };

            return (string) $asset->deliveryType('authenticated')->signUrl(true)->toUrl();
        } catch (\Throwable $e) {
            Log::error('Cloudinary signedUrl failed', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'publicId' => $publicId,
            ]);
            throw new RuntimeException('Failed to generate signed URL.', 0, $e);
        }
    }
}