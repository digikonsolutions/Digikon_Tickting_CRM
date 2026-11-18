<?php

namespace App\Services;

use Cloudinary\Configuration\Configuration;
use Cloudinary\CloudinaryEngine;
use Cloudinary\Api\Admin\AdminApi;
use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CloudinaryUploader
{
    protected $cloudinary;
    protected $adminApi;
    protected $config;

    public function __construct()
    {
        // Load configuration from env('CLOUDINARY_URL') only; other defaults via config/cloudinary.php
        $this->config = config('cloudinary');
        $this->cloudinary = new CloudinaryEngine([
            'cloud' => [
                'cloud_name' => $this->extractCloudName(),
                'api_key'    => $this->extractApiKey(),
                'api_secret' => $this->extractApiSecret(),
            ],
        ]);
        $this->adminApi = new AdminApi($this->cloudinary);
    }

    /**
     * Upload a file to Cloudinary.
     *
     * @param UploadedFile $file   The uploaded file from the request.
     * @param string       $folder Destination folder inside the Cloudinary root.
     * @param array        $options Additional upload options (e.g. transformations).
     *
     * @return array Keys: public_id, secure_url, resource_type, format, bytes, width, height
     * @throws RuntimeException on failure, preserving original exception as previous.
     */
    public function upload(UploadedFile $file, string $folder, array $options = []): array
    {
        try {
            $uploadApi = new UploadApi($this->cloudinary);
            $result = $uploadApi->upload($file->getRealPath(), array_merge([
                'folder' => $folder,
                'resource_type' => 'image',
                'use_filename' => true,
                'unique_filename' => false,
                'overwrite' => false,
            ], $options));

            return [
                'public_id'    => $result['public_id'],
                'secure_url'   => $result['secure_url'],
                'resource_type'=> $result['resource_type'],
                'format'       => $result['format'],
                'bytes'        => $result['bytes'],
                'width'        => $result['width'] ?? null,
                'height'       => $result['height'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Cloudinary upload failed', [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
            ]);
            throw new RuntimeException('Failed to upload image to Cloudinary.', 0, $e);
        }
    }

    /**
     * Delete an asset by public ID.
     *
     * @param string $publicId
     * @param string $resourceType (default "image")
     * @return bool
     * @throws RuntimeException on failure.
     */
    public function delete(string $publicId, string $resourceType = 'image'): bool
    {
        try {
            $adminApi = $this->adminApi;
            $adminApi->deleteAssets([$publicId], ['resource_type' => $resourceType]);
            return true;
        } catch (\Exception $e) {
            Log::error('Cloudinary delete failed', [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'publicId'=> $publicId,
            ]);
            throw new RuntimeException('Failed to delete asset from Cloudinary.', 0, $e);
        }
    }

    /**
     * Generate a signed URL for a private asset. Public assets (avatars, logos) use the secure_url directly.
     *
     * @param string $publicId
     * @param string $resourceType
     * @param int    $expiresSeconds
     * @return string
     * @throws RuntimeException on failure.
     */
    public function signedUrl(string $publicId, string $resourceType = 'image', int $expiresSeconds = 600): string
    {
        try {
            $adminApi = $this->adminApi;
            $url = $adminApi->resourceUrl($publicId, [
                'resource_type' => $resourceType,
                'type'          => 'upload', // default type
                'sign_url'     => true,
                'expire'        => $expiresSeconds,
            ]);
            return $url;
        } catch (\Exception $e) {
            Log::error('Cloudinary signedUrl failed', [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'publicId'=> $publicId,
            ]);
            throw new RuntimeException('Failed to generate signed URL.', 0, $e);
        }
    }

    /**
     * Helper to extract cloud name, api key, secret from CLOUDINARY_URL.
     */
    private function extractCloudName(): string
    {
        $url = $this->config['url'] ?? '';
        // Expected format: cloudinary://<key>:<secret>@<cloud_name>
        if (preg_match('#cloudinary://[^:]+:[^@]+@([^/]+)#', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extractApiKey(): string
    {
        $url = $this->config['url'] ?? '';
        if (preg_match('#cloudinary://([^:]+):[^@]+@#', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extractApiSecret(): string
    {
        $url = $this->config['url'] ?? '';
        if (preg_match('#cloudinary://[^:]+:([^@]+)@#', $url, $m)) {
            return $m[1];
        }
        return '';
    }
}
?>