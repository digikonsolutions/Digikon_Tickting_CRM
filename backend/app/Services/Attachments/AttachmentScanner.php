<?php

namespace App\Services\Attachments;

use Illuminate\Http\UploadedFile;

/**
 * Hook for an antivirus/content scanner. Bind a real implementation in the service container to plug
 * one in; controllers and the attachment service never change.
 */
interface AttachmentScanner
{
    /** @return bool true when the file is clean and may be stored */
    public function isClean(UploadedFile $file): bool;
}
