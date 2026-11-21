<?php

namespace App\Services;

use App\Models\AgencyContract;
use App\Models\ContractDocument;
use App\Models\User;
use App\Services\Attachments\AttachmentScanner;
use App\Services\Attachments\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Contract files live on the private disk under contracts/{agency_id}/ with random names; paths never leave the server. */
class ContractDocumentService
{
    public function __construct(private AttachmentService $files, private AttachmentScanner $scanner) {}

    public function maxPerContract(): int
    {
        return (int) config('attachments.contract_documents.max_per_contract', 20);
    }

    /** Returns an error message, or null when the file is acceptable. The MIME type is sniffed from the content. */
    public function problem(UploadedFile $file): ?string
    {
        $name = AttachmentService::sanitizeName((string) $file->getClientOriginalName());

        if (! $file->isValid()) {
            return "{$name} could not be uploaded.";
        }
        if ($file->getSize() === 0) {
            return "{$name} is empty.";
        }
        $maxKb = (int) config('attachments.contract_documents.max_kb', 10240);
        if ($file->getSize() > $maxKb * 1024) {
            return "{$name} is larger than ".round($maxKb / 1024, 1).' MB.';
        }
        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $allowed = (array) config('attachments.contract_documents.allowed', []);
        if ($extension === '' || ! isset($allowed[$extension])) {
            return "{$name}: only PDF, JPG and PNG files are allowed.";
        }
        if (! in_array($this->files->detectMime((string) $file->getRealPath()), $allowed[$extension], true)) {
            return "{$name}: the file content does not match its .{$extension} extension.";
        }
        if (! $this->scanner->isClean($file)) {
            return "{$name} was rejected by the security scan.";
        }

        return null;
    }

    public function store(AgencyContract $contract, User $actor, UploadedFile $file): ContractDocument
    {
        $disk = $this->files->disk();
        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $path = Storage::disk($disk)->putFileAs("contracts/{$contract->agency_id}", $file, (string) Str::uuid().'.'.$extension);
        if ($path === false) {
            throw new RuntimeException('The contract document could not be stored.');
        }

        return ContractDocument::create([
            'agency_contract_id' => $contract->id,
            'agency_id' => $contract->agency_id,
            'original_name' => AttachmentService::sanitizeName((string) $file->getClientOriginalName()),
            'path' => $path,
            'disk' => $disk,
            'mime' => $this->files->detectMime((string) $file->getRealPath()),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $actor->id,
        ]);
    }
}
