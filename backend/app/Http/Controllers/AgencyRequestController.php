<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AgencyRequest;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\EmployeeSeats;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Request more hours / an employee / an upgrade / a renewal", with notes and optional files. Requests are stored
 * so the sender can see what they asked for afterwards; every admin is notified. An owner sees every request of
 * the agency, a client only their own. Files are private and tenant-scoped; anyone else gets 404.
 */
class AgencyRequestController extends Controller
{
    public function __construct(private AttachmentService $files) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $requests = AgencyRequest::query()->with(['attachments', 'user'])
            ->where('agency_id', $user->agency_id)
            ->when($user->role !== Role::AgencyOwner, fn ($query) => $query->where('user_id', $user->id))
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->latest('id')->limit(50)->get();

        return response()->json(['requests' => $requests->map(fn (AgencyRequest $r): array => $this->payload($r))->values()]);
    }

    public function catalog(): JsonResponse
    {
        return response()->json(\App\Support\ServiceCatalog::payload());
    }

    public function store(Request $request, WorkflowNotificationService $notifications): JsonResponse
    {
        $user = $request->user()->loadMissing('agency');
        $data = $request->validate([
            'type' => ['required', Rule::in(AgencyRequest::TYPES)],
            'message' => ['nullable', 'string', 'max:1000'],
            'files' => ['sometimes', 'array', 'max:'.(int) config('attachments.max_files_per_upload')],
            'files.*' => ['file'],
            // Only "request an employee" has structured answers: a role, hours per week and how long.
            'details' => ['sometimes', 'nullable', 'array:role,hours_per_week,duration_weeks', Rule::prohibitedIf(fn () => $request->input('type') !== 'employee')],
            'details.role' => ['required_with:details', Rule::in(array_keys(config('service_catalog.employee_request.roles')))],
            'details.hours_per_week' => ['required_with:details', 'integer', 'min:1', 'max:'.(int) config('service_catalog.employee_request.max_hours_per_week')],
            'details.duration_weeks' => ['required_with:details', 'integer', 'min:1', 'max:'.(int) config('service_catalog.employee_request.max_duration_weeks')],
        ]);
        // Employees are requested by owners; clients ask their owner.
        if ($data['type'] === 'employee' && $user->role !== Role::AgencyOwner) {
            abort(403, 'Only the agency owner can request an employee.');
        }
        $files = array_values($request->file('files', []));
        $errors = [];
        foreach ($files as $index => $file) {
            $problem = $file instanceof UploadedFile ? $this->files->problem($file) : 'Invalid upload.';
            if ($problem !== null) {
                $errors["files.{$index}"] = [$problem];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $stored = [];
        try {
            $agencyRequest = DB::transaction(function () use ($user, $data, $files, &$stored): AgencyRequest {
                $row = AgencyRequest::create(['agency_id' => $user->agency_id, 'user_id' => $user->id, 'type' => $data['type'], 'message' => $data['message'] ?? null, 'details' => $data['details'] ?? null]);
                foreach ($files as $file) {
                    $stored[] = $this->files->storeForRequest($row, $user, $file);
                }

                return $row;
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }
            throw $exception;
        }

        $notified = $notifications->agencyRequest($user->agency, $user, $data['type'], $this->withDetails($data));

        return response()->json([
            'message' => 'Your request was sent. We will get back to you.',
            'notified' => $notified,
            'request' => $this->payload($agencyRequest->load(['attachments', 'user'])),
        ], 201);
    }

    /** What the Employees page needs: seats, whether the owner may add, and the owner's earlier employee requests. */
    public function employees(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'seats' => app(EmployeeSeats::class)->summary($user->agency_id),
            'can_add' => $user->role === Role::AgencyOwner && app(EmployeeSeats::class)->summary($user->agency_id)['can_add'],
            'requests' => AgencyRequest::query()->with(['attachments', 'user'])->where('agency_id', $user->agency_id)->where('type', 'employee')
                ->when($user->role !== Role::AgencyOwner, fn ($q) => $q->where('user_id', $user->id))->latest('id')->limit(10)->get()
                ->map(fn (AgencyRequest $r): array => $this->payload($r))->values(),
        ]);
    }

    public function download(Request $request, Attachment $attachment): Response
    {
        abort_unless($attachment->attachable_type === AgencyRequest::class, 404);
        $agencyRequest = AgencyRequest::query()->findOrFail($attachment->attachable_id);
        abort_unless($this->canView($request->user(), $agencyRequest), 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'attachment');
    }

    private function canView(User $user, AgencyRequest $agencyRequest): bool
    {
        return match ($user->role) {
            Role::Admin => true,
            Role::AgencyOwner => $user->agency_id === $agencyRequest->agency_id,
            Role::AgencyClient => $user->agency_id === $agencyRequest->agency_id && $agencyRequest->user_id === $user->id,
            default => false,
        };
    }

    /** @return array<string, mixed> */
    /** The note the admins read: the structured answers first, then the person's own words. */
    private function withDetails(array $data): ?string
    {
        $details = $data['details'] ?? null;
        if (empty($details)) {
            return $data['message'] ?? null;
        }
        $roles = config('service_catalog.employee_request.roles');
        $line = ($roles[$details['role']] ?? $details['role']).', '.$details['hours_per_week'].' h per week for '.$details['duration_weeks'].' week(s).';

        return trim($line.' '.($data['message'] ?? ''));
    }

    private function payload(AgencyRequest $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'message' => $r->message,
            'details' => $r->details,
            'status' => $r->status,
            'requested_by' => $r->user?->name,
            'created_at' => $r->created_at?->toISOString(),
            'attachments' => $r->attachments->map(fn (Attachment $a): array => [
                'id' => $a->id, 'name' => $a->original_name, 'size_bytes' => $a->size, 'mime_type' => $a->mime,
                'download_url' => "/api/agency/request-attachments/{$a->id}/download",
            ])->values(),
        ];
    }
}
