<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Attachment;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\Attachments\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files attached to a project brief. Visible to the project's agency, its assigned employee and admins;
 * only the agency's owner and clients add or remove files. Anyone else gets 404, so ids reveal nothing.
 */
class ProjectAttachmentController extends Controller
{
    public function __construct(private AttachmentService $files) {}

    private function canView(User $user, WorkItem $project): bool
    {
        return match ($user->role) {
            Role::Admin => true,
            Role::Employee => $project->employee_id === $user->id,
            Role::AgencyOwner, Role::AgencyClient, Role::AgencyMember => $user->agency_id !== null && $user->agency_id === $project->agency_id,
            default => false,
        };
    }

    private function canManage(User $user, WorkItem $project): bool
    {
        return in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)
            && $user->agency_id !== null
            && $user->agency_id === $project->agency_id;
    }

    private function authorizeView(Request $request, WorkItem $project): User
    {
        $user = $request->user();
        abort_unless($this->canView($user, $project), 404);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(Attachment $attachment, User $viewer, WorkItem $project): array
    {
        $isImage = $this->files->isInlineSafe($attachment);

        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime,
            'size_bytes' => $attachment->size,
            'is_image' => $isImage,
            'uploaded_by' => $attachment->uploader_name,
            'created_at' => $attachment->created_at?->toISOString(),
            'can_delete' => $this->canDelete($viewer, $attachment, $project),
        ];
    }

    private function canDelete(User $user, Attachment $attachment, WorkItem $project): bool
    {
        return $this->canManage($user, $project)
            && ($attachment->uploaded_by === $user->id || $user->role === Role::AgencyOwner);
    }

    /** @return array<string, mixed> */
    private function limits(): array
    {
        $types = array_keys($this->files->allowedTypes((bool) config('attachments.brief_allow_archives')));

        return [
            'max_file_kb' => (int) config('attachments.max_file_kb'),
            'max_files_per_upload' => (int) config('attachments.max_files_per_upload'),
            'extensions' => $types,
        ];
    }

    public function index(Request $request, WorkItem $workItem): JsonResponse
    {
        $user = $this->authorizeView($request, $workItem);

        return response()->json([
            'attachments' => $workItem->attachments()->orderBy('id')->get()
                ->map(fn (Attachment $a): array => $this->payload($a, $user, $workItem))->values(),
            'limits' => $this->limits(),
            'can_upload' => $this->canManage($user, $workItem) && in_array($workItem->status, ['scheduled', 'in_progress'], true),
        ]);
    }

    public function store(Request $request, WorkItem $workItem): JsonResponse
    {
        $user = $this->authorizeView($request, $workItem);
        abort_unless($this->canManage($user, $workItem), 403, 'Only your agency can add files to this project.');
        abort_unless(in_array($workItem->status, ['scheduled', 'in_progress'], true), 422, 'Files cannot be added to a closed project.');

        $request->validate(['files' => ['required', 'array', 'min:1'], 'files.*' => ['file']]);
        $files = array_values($request->file('files'));

        $perUpload = (int) config('attachments.max_files_per_upload');
        $perProject = (int) config('attachments.max_files_per_project');
        if (count($files) > $perUpload) {
            throw ValidationException::withMessages(['files' => "You can upload at most {$perUpload} files at a time."]);
        }
        if ($workItem->attachments()->count() + count($files) > $perProject) {
            throw ValidationException::withMessages(['files' => "A project can hold at most {$perProject} files."]);
        }

        $archives = (bool) config('attachments.brief_allow_archives');
        $errors = [];
        foreach ($files as $index => $file) {
            $problem = $file instanceof UploadedFile ? $this->files->problem($file, $archives) : 'Invalid upload.';
            if ($problem !== null) {
                $errors["files.{$index}"] = [$problem];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $stored = [];
        try {
            DB::transaction(function () use ($workItem, $user, $files, &$stored): void {
                foreach ($files as $file) {
                    $stored[] = $this->files->storeForProject($workItem, $user, $file);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $row) {
                Storage::disk($row->disk)->delete($row->path);
            }
            throw $exception;
        }

        return response()->json([
            'attachments' => collect($stored)->map(fn (Attachment $a): array => $this->payload($a, $user, $workItem))->values(),
        ], 201);
    }

    public function download(Request $request, Attachment $attachment): Response
    {
        $project = $this->project($attachment);
        $this->authorizeView($request, $project);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        $inline = $request->boolean('inline') && $this->files->isInlineSafe($attachment);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, Attachment $attachment): JsonResponse
    {
        $project = $this->project($attachment);
        $user = $this->authorizeView($request, $project);
        abort_unless($this->canDelete($user, $attachment, $project), 403, 'You cannot remove this file.');

        // Soft delete: the file stays on disk until a retention job removes it.
        $attachment->delete();

        return response()->json(['message' => 'The file was removed.']);
    }

    private function project(Attachment $attachment): WorkItem
    {
        abort_unless($attachment->attachable_type === WorkItem::class, 404);

        return WorkItem::query()->findOrFail($attachment->attachable_id);
    }
}
