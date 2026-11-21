<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreEmployeeDocumentRequest;
use App\Http\Resources\AdminEmployeeProfileResource;
use App\Models\AuditLog;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\EmployeeDocumentService;
use App\Services\EmployeeProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** HR documents of one employee. Administrators only; every download is audited. */
class EmployeeDocumentController extends Controller
{
    public function __construct(private EmployeeDocumentService $documents, private EmployeeProfileService $profiles) {}

    public function store(StoreEmployeeDocumentRequest $request, int $id): JsonResponse
    {
        $employee = $this->employee($id);
        $file = $request->file('file');

        if ($employee->employeeDocuments()->count() >= $this->documents->maxPerEmployee()) {
            throw ValidationException::withMessages(['file' => "An employee can have at most {$this->documents->maxPerEmployee()} documents."]);
        }
        if ($problem = $this->documents->problem($file)) {
            throw ValidationException::withMessages(['file' => $problem]);
        }

        $document = $this->documents->store($employee, $request->user(), $file, $request->validated('type'), $request->validated('expires_at'));
        AuditLog::record($request->user(), 'updated_profile', $employee, $request);

        return response()->json([
            'document' => AdminEmployeeProfileResource::documentPayload($document),
            'completeness' => $this->profiles->completeness($employee->fresh()),
        ], 201);
    }

    /** Streams the file after the role check; the storage path is never exposed. */
    public function download(Request $request, int $id, int $document): Response
    {
        $employee = $this->employee($id);
        $doc = $this->document($employee, $document);
        $disk = Storage::disk($doc->disk);
        abort_unless($disk->exists($doc->path), 404);

        AuditLog::record($request->user(), 'downloaded_document', $doc, $request);

        $inline = $request->boolean('inline') && in_array($doc->mime, config('attachments.inline_mimes', []), true);

        return $disk->response($doc->path, $doc->original_name, [
            'Content-Type' => $doc->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    public function verify(Request $request, int $id, int $document): JsonResponse
    {
        $employee = $this->employee($id);
        $doc = $this->document($employee, $document);
        $data = $request->validate(['verified' => ['required', 'boolean']]);

        $doc->forceFill([
            'verified_at' => $data['verified'] ? now() : null,
            'verified_by' => $data['verified'] ? $request->user()->id : null,
        ])->save();
        AuditLog::record($request->user(), 'updated_profile', $employee, $request);

        return response()->json(['document' => AdminEmployeeProfileResource::documentPayload($doc->fresh('verifier'))]);
    }

    public function destroy(Request $request, int $id, int $document): JsonResponse
    {
        $employee = $this->employee($id);
        $this->documents->delete($this->document($employee, $document));
        AuditLog::record($request->user(), 'updated_profile', $employee, $request);

        return response()->json([
            'message' => 'The document was removed.',
            'completeness' => $this->profiles->completeness($employee->fresh()),
        ]);
    }

    private function employee(int $id): User
    {
        return User::query()->where('role', Role::Employee->value)->findOrFail($id);
    }

    /** Scoped to the employee in the URL, so a document id from another employee is a 404. */
    private function document(User $employee, int $documentId): EmployeeDocument
    {
        return $employee->employeeDocuments()->whereKey($documentId)->firstOrFail();
    }
}
