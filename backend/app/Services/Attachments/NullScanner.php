<?php

namespace App\Services\Attachments;

use Illuminate\Http\UploadedFile;

/** Default scanner: accepts everything. Replace the container binding to enable real scanning. */
class NullScanner implements AttachmentScanner
{
    public function isClean(UploadedFile $file): bool
    {
        return true;
    }
}
