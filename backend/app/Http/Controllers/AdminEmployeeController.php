<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AdminStoreEmployeeRequest;
use App\Http\Requests\AdminUpdateEmployeeRequest;
use App\Http\Resources\AdminEmployeeProfileResource;
use App\Models\AuditLog;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\AvatarService;
use App\Services\EmployeeDocumentService;
use App\Services\EmployeeProfileService;
use App\Services\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Administrator-only employee profiles. Agency users never reach these routes (see routes/api.php). */
class AdminEmployeeController extends Controller
{
    public function __construct(
        private EmployeeProfileService $profiles,
        private EmployeeDocumentService $documents,
    ) {}

    /**
     * One transaction creates the account, profile, lists and documents, then sends the invitation. If the
     * email cannot be sent everything rolls back and any stored files are removed again.
     */
    public function store(AdminStoreEmployeeRequest $request, UserAccountService $accounts): JsonResponse
    {
        $data = $request->validated();
        $uploads = $this->uploadsFrom($data['documents'] ?? []);
        unset($data['documents']);
        $this->guardDocuments($uploads, 0);

        $stored = [];
        try {
            $employee = $accounts->createInvitedUser(
                ['name' => $data['name'], 'email' => $data['email'], 'role' => Role::Employee->value],
                function (User $employee) use ($data, $uploads, $request, &$stored): void {
                    $this->profiles->apply($employee, $data);
                    foreach ($uploads as $upload) {
                        $stored[] = $this->documents->store($employee, $request->user(), $upload['file'], $upload['type'], $upload['expires_at']);
                    }
                }
            );
        } catch (\Throwable $exception) {
            // The rows rolled back; the files did not, so remove what was already written.
            foreach ($stored as $document) {
                \Illuminate\Support\Facades\Storage::disk($document->disk)->delete($document->path);
            }
            throw $exception;
        }

        AuditLog::record($request->user(), 'updated_profile', $employee, $request);

        return response()->json(['employee' => $this->present($employee->fresh())], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->findEmployee($id);
        AuditLog::record($request->user(), 'viewed_sensitive', $employee, $request);

        return response()->json(['employee' => $this->present($employee)]);
    }

    public function update(AdminUpdateEmployeeRequest $request, int $id): JsonResponse
    {
        $employee = $this->findEmployee($id);
        $data = $request->validated();

        DB::transaction(fn () => $this->profiles->apply($employee, $data));
        AuditLog::record($request->user(), 'updated_profile', $employee, $request);

        return response()->json(['employee' => $this->present($employee->fresh())]);
    }

    /** The full CNIC. Rate limited, and every call is written to audit_logs. */
    public function revealCnic(Request $request, int $id): JsonResponse
    {
        $employee = $this->findEmployee($id);
        $profile = $employee->employeeProfile;
        abort_if($profile === null || blank($profile->cnic), 404, 'No CNIC is on file for this employee.');

        AuditLog::record($request->user(), 'revealed_cnic', $employee, $request);

        return response()->json(['cnic' => $profile->cnicFormatted()])
            ->header('Cache-Control', 'private, no-store');
    }

    /** Reuses the app's profile-photo pipeline (Cloudinary, 512px face crop) instead of a second one. */
    public function uploadPhoto(Request $request, int $id, AvatarService $avatars): JsonResponse
    {
        $employee = $this->findEmployee($id);
        $request->validate(['photo' => ['required', 'file']]);
        $file = $request->file('photo');

        if ($problem = $avatars->problem($file)) {
            throw ValidationException::withMessages(['photo' => $problem]);
        }
        $avatars->store($employee, $file);
        AuditLog::record($request->user(), 'updated_profile', $employee, $request);

        return response()->json(['employee' => $this->present($employee->fresh())]);
    }

    public function deletePhoto(Request $request, int $id, AvatarService $avatars): JsonResponse
    {
        $employee = $this->findEmployee($id);
        $avatars->remove($employee);
        AuditLog::record($request->user(), 'updated_profile', $employee, $request);

        return response()->json(['employee' => $this->present($employee->fresh())]);
    }

    private function findEmployee(int $id): User
    {
        return User::query()->where('role', Role::Employee->value)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(User $employee): array
    {
        return (new AdminEmployeeProfileResource($employee))->resolve();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{file: UploadedFile, type: string, expires_at: ?string}>
     */
    private function uploadsFrom(array $rows): array
    {
        return collect($rows)->map(fn (array $row): array => [
            'file' => $row['file'],
            'type' => $row['type'],
            'expires_at' => $row['expires_at'] ?? null,
        ])->values()->all();
    }

    /** @param  list<array{file: UploadedFile, type: string, expires_at: ?string}>  $uploads */
    private function guardDocuments(array $uploads, int $existing): void
    {
        if ($existing + count($uploads) > $this->documents->maxPerEmployee()) {
            throw ValidationException::withMessages(['documents' => "An employee can have at most {$this->documents->maxPerEmployee()} documents."]);
        }
        $errors = [];
        foreach ($uploads as $index => $upload) {
            if ($problem = $this->documents->problem($upload['file'])) {
                $errors["documents.{$index}.file"] = [$problem];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
