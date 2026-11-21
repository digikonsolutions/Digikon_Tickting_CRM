<?php

namespace App\Services;

use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Attachments\AttachmentScanner;
use App\Services\Attachments\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HR documents live on the private disk under employees/{user_id}/ with random file names. The client's
 * file name is only stored as display text, and the storage path is never sent to any client.
 */
class EmployeeDocumentService
{
    public function __construct(private AttachmentService $files, private AttachmentScanner $scanner) {}

    public function maxPerEmployee(): int
    {
        return (int) config('attachments.employee_documents.max_per_employee', 40);
    }

    /** Returns an error message, or null when the file is acceptable. MIME is sniffed from the content. */
    public function problem(UploadedFile $file): ?string
    {
        $name = AttachmentService::sanitizeName((string) $file->getClientOriginalName());

        if (! $file->isValid()) {
            return "{$name} could not be uploaded.";
        }
        if ($file->getSize() === 0) {
            return "{$name} is empty.";
        }
        $maxKb = (int) config('attachments.employee_documents.max_kb', 5120);
        if ($file->getSize() > $maxKb * 1024) {
            return "{$name} is larger than ".round($maxKb / 1024, 1).' MB.';
        }

        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $allowed = (array) config('attachments.employee_documents.allowed', []);
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

    public function store(User $employee, User $actor, UploadedFile $file, string $type, ?string $expiresAt = null): EmployeeDocument
    {
        $disk = $this->files->disk();
        $extension = strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
        $path = Storage::disk($disk)->putFileAs("employees/{$employee->id}", $file, (string) Str::uuid().'.'.$extension);
        if ($path === false) {
            throw new RuntimeException('The document could not be stored.');
        }

        return EmployeeDocument::create([
            'user_id' => $employee->id,
            'type' => $type,
            'original_name' => AttachmentService::sanitizeName((string) $file->getClientOriginalName()),
            'path' => $path,
            'disk' => $disk,
            'mime' => $this->files->detectMime((string) $file->getRealPath()),
            'size' => (int) $file->getSize(),
            'expires_at' => $expiresAt,
            'uploaded_by' => $actor->id,
        ]);
    }

    public function delete(EmployeeDocument $document): void
    {
        $disk = $document->disk;
        $path = $document->path;
        $document->delete();
        Storage::disk($disk)->delete($path);
    }

    /**
     * Files of an employee that is about to be deleted. Read these BEFORE the user row goes (the foreign
     * key cascade removes the document rows), then pass them to deleteFiles() once the delete committed.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function pathsFor(User $employee): Collection
    {
        return $employee->employeeDocuments()->get(['disk', 'path'])
            ->map(fn (EmployeeDocument $d): array => ['disk' => $d->disk, 'path' => $d->path])
            ->values();
    }

    /** @param  Collection<int, array{disk: string, path: string}>  $paths */
    public function deleteFiles(Collection $paths, int $employeeId): void
    {
        foreach ($paths as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
        Storage::disk($this->files->disk())->deleteDirectory("employees/{$employeeId}");
    }
}
