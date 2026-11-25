<?php

namespace Tests;

use App\Services\CloudinaryUploader;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeCloudinaryUploader;

abstract class TestCase extends BaseTestCase
{
    protected FakeCloudinaryUploader $cloudinary;

    protected function setUp(): void
    {
        parent::setUp();

        // The two-stage review is on in production. Older tests describe the one-stage flow; the review tests switch it on.
        config(['scheduling.internal_review' => false]);

        // Branding logos and profile photos go to Cloudinary; tests must never reach the real account.
        $this->cloudinary = new FakeCloudinaryUploader();
        $this->app->instance(CloudinaryUploader::class, $this->cloudinary);
    }
}
