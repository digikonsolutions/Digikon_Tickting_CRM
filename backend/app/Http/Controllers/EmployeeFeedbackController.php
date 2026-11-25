<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeFeedback;
use App\Models\EmployeeFeedbackReply;
use App\Models\User;
use App\Notifications\FeedbackNotice;
use App\Services\EmployeeFeedbackService;
use App\Services\TicketNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Feedback an agency owner writes about an employee they held. The owner writes (and edits for a few days), clients of
 * the agency read, the employee reads a shared version and replies once, and admins read everything and may remove.
 * Every agency sees only its own feedback.
 */
class EmployeeFeedbackController extends Controller
{
    public function __construct(private EmployeeFeedbackService $feedback, private TicketNotifier $notifier) {}

    /* ---------------------------------------------------------------- agency */

    public function form(): JsonResponse
    {
        return response()->json($this->feedback->form());
    }

    /** One average per employee of this agency, for the team table. */
    public function agencySummary(Request $request): JsonResponse
    {
        $rows = EmployeeFeedback::query()->published()->where('agency_id', $request->user()->agency_id)
            ->selectRaw('employee_id, COUNT(*) AS total, AVG(rating) AS average')->groupBy('employee_id')->get();

        return response()->json(['summaries' => $rows->mapWithKeys(fn ($row): array => [(string) $row->employee_id => ['count' => (int) $row->total, 'average' => round((float) $row->average, 1)]])]);
    }

    public function agencyIndex(Request $request, int $employee): JsonResponse
    {
        $user = $request->user();
        abort_unless(EmployeeAssignment::query()->where('agency_id', $user->agency_id)->where('employee_id', $employee)->exists(), 404);
        $rows = EmployeeFeedback::query()->published()->where('agency_id', $user->agency_id)->where('employee_id', $employee)
            ->with(['agency', 'author:id,name', 'workItem:id,title', 'reply'])->orderByDesc('id')->get();
        // Projects this employee did for the agency, so the form can link the feedback to one.
        $projects = \App\Models\WorkItem::query()->where('agency_id', $user->agency_id)->where('employee_id', $employee)->orderByDesc('id')->limit(50)->get(['id', 'title', 'status']);

        return response()->json([
            'summary' => $this->feedback->summary($rows),
            'feedback' => $rows->map(fn (EmployeeFeedback $f): array => $this->feedback->present($f, $user))->values(),
            'projects' => $projects,
            'can_write' => $user->role === Role::AgencyOwner,
        ]);
    }

    public function store(Request $request, int $employee): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request);
        [$assignment, $project] = $this->feedback->resolve($user, $employee, $data['work_item_id'] ?? null);

        $created = DB::transaction(function () use ($user, $employee, $data, $assignment, $project): EmployeeFeedback {
            // Locked rows keep two quick submissions from both passing the checks below.
            EmployeeAssignment::query()->lockForUpdate()->find($assignment->id);
            $base = EmployeeFeedback::query()->published()->where('agency_id', $user->agency_id)->where('employee_id', $employee);
            if ((clone $base)->where('assignment_id', $assignment->id)->where('work_item_id', $project?->id)->exists()) {
                abort(422, $project ? 'You already gave feedback for this project. Pick another project, or edit the first one while it is open.' : 'You already gave general feedback for this assignment. Link a project to add more.');
            }
            if ((clone $base)->where('author_id', $user->id)->whereDate('created_at', now()->toDateString())->exists()) {
                abort(429, 'You already gave feedback about this employee today.');
            }

            return EmployeeFeedback::create([
                ...collect($data)->except(['work_item_id'])->all(),
                'agency_id' => $user->agency_id, 'employee_id' => $employee, 'assignment_id' => $assignment->id, 'work_item_id' => $project?->id,
                'author_id' => $user->id, 'editable_until' => now()->addDays((int) config('feedback.edit_days')),
            ]);
        });

        $this->tell($created, $user);

        return response()->json(['feedback' => $this->feedback->present($created->fresh(), $user)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = EmployeeFeedback::query()->where('agency_id', $user->agency_id)->findOrFail($id);
        abort_unless($row->status === 'published', 404);
        abort_unless($row->isEditable(), 422, 'This feedback is locked. It could be edited for '.config('feedback.edit_days').' days after it was saved.');
        $data = collect($this->validated($request, creating: false))->except(['work_item_id'])->all();
        $row->update($data);

        return response()->json(['feedback' => $this->feedback->present($row->fresh(), $user)]);
    }

    /* -------------------------------------------------------------- employee */

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = EmployeeFeedback::query()->published()->where('employee_id', $user->id)->with(['agency', 'workItem:id,title', 'reply'])->orderByDesc('id')->get();

        return response()->json(['summary' => $this->feedback->summary($rows), 'feedback' => $rows->map(fn (EmployeeFeedback $f): array => $this->feedback->present($f, $user))->values()]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row = EmployeeFeedback::query()->published()->where('employee_id', $user->id)->findOrFail($id);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:500']]);
        abort_if($row->reply()->exists(), 422, 'You already replied to this feedback.');
        EmployeeFeedbackReply::create(['feedback_id' => $row->id, 'employee_id' => $user->id, 'body' => trim($data['body'])]);

        $recipients = User::query()->where('role', Role::AgencyOwner->value)->where('agency_id', $row->agency_id)->get()
            ->merge(User::query()->where('role', Role::Admin->value)->get());
        foreach ($recipients->unique('id')->where('is_active', true) as $person) {
            $this->notifier->send($person, FeedbackNotice::class, [
                'title' => 'Reply to your feedback', 'body' => $user->name.' replied to the feedback about them.',
                'url_path' => $person->role === Role::Admin ? '/admin/employees/'.$row->employee_id : '/agency/employees', 'actor_name' => $user->name,
            ]);
        }

        return response()->json(['feedback' => $this->feedback->present($row->fresh(), $user)], 201);
    }

    /* ----------------------------------------------------------------- admin */

    public function adminSummary(): JsonResponse
    {
        $rows = EmployeeFeedback::query()->published()->selectRaw('employee_id, COUNT(*) AS total, AVG(rating) AS average')->groupBy('employee_id')->get();

        return response()->json(['summaries' => $rows->mapWithKeys(fn ($row): array => [(string) $row->employee_id => ['count' => (int) $row->total, 'average' => round((float) $row->average, 1)]])]);
    }

    public function adminShow(Request $request, int $id): JsonResponse
    {
        $employee = User::query()->where('role', Role::Employee->value)->findOrFail($id);
        $rows = EmployeeFeedback::query()->where('employee_id', $employee->id)->with(['agency', 'author:id,name', 'workItem:id,title', 'reply'])->orderByDesc('id')->get();
        $published = $rows->where('status', 'published');
        $perAgency = $published->groupBy('agency_id')->map(fn ($group): array => [
            'agency_id' => $group->first()->agency_id, 'agency_name' => $group->first()->agency?->name, 'count' => $group->count(), 'average' => round($group->avg('rating'), 1),
        ])->values();

        return response()->json([
            'summary' => $this->feedback->summary($published->values()),
            'per_agency' => $perAgency,
            'feedback' => $rows->map(fn (EmployeeFeedback $f): array => $this->feedback->present($f, $request->user()))->values(),
        ]);
    }

    public function remove(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $row = EmployeeFeedback::query()->published()->findOrFail($id);
        $row->update(['status' => 'removed', 'removed_by' => $request->user()->id, 'removed_reason' => $data['reason'], 'removed_at' => now()]);
        AuditLog::record($request->user(), 'removed_feedback', $row, $request);

        return response()->json(['feedback' => $this->feedback->present($row->fresh(), $request->user())]);
    }

    /* --------------------------------------------------------------- helpers */

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating = true): array
    {
        $rules = [
            'rating' => ['required', 'integer', 'between:1,5'],
            'ratings' => ['nullable', 'array'],
            'ratings.*' => ['nullable', 'integer', 'between:1,5'],
            'would_again' => ['required', Rule::in(array_keys(config('feedback.would_again')))],
            'went_well' => ['nullable', 'string', 'max:3000'],
            'to_improve' => ['nullable', 'string', 'max:3000'],
            'highlights' => ['nullable', 'array', 'max:12'],
            'highlights.*' => ['string', Rule::in(array_keys(config('feedback.highlights')))],
            'concerns' => ['nullable', 'array', 'max:12'],
            'concerns.*' => ['string', Rule::in(array_keys(config('feedback.concerns')))],
            'private_note' => ['nullable', 'string', 'max:2000'],
            'work_item_id' => $creating ? ['nullable', 'integer'] : ['prohibited'],
        ];
        $data = $request->validate($rules);
        // Only the known categories are kept, and an unanswered one is dropped rather than stored as 0.
        $data['ratings'] = collect($data['ratings'] ?? [])->only(array_keys(config('feedback.categories')))->filter()->all() ?: null;
        $data['highlights'] = array_values(array_unique($data['highlights'] ?? [])) ?: null;
        $data['concerns'] = array_values(array_unique($data['concerns'] ?? [])) ?: null;
        $data['went_well'] = filled($data['went_well'] ?? null) ? trim($data['went_well']) : null;
        $data['to_improve'] = filled($data['to_improve'] ?? null) ? trim($data['to_improve']) : null;
        if ($data['went_well'] === null && $data['to_improve'] === null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['went_well' => 'Write what went well, what could be better, or both.']);
        }

        return $data;
    }

    private function tell(EmployeeFeedback $feedback, User $author): void
    {
        $employee = User::query()->find($feedback->employee_id);
        if ($employee) {
            $this->notifier->send($employee, FeedbackNotice::class, [
                'title' => 'New feedback about you', 'body' => 'An agency shared feedback about your work. You can read it and reply.', 'url_path' => '/my-feedback',
            ]);
        }
        if ($feedback->rating <= (int) config('feedback.alert_rating') || $feedback->would_again === 'no') {
            foreach (User::query()->where('role', Role::Admin->value)->where('is_active', true)->get() as $admin) {
                $this->notifier->send($admin, FeedbackNotice::class, [
                    'title' => 'Low feedback about an employee', 'body' => ($employee?->name ?? 'An employee').' received '.$feedback->rating.'/5'.($feedback->would_again === 'no' ? ' and "would not work again"' : '').'.',
                    'url_path' => '/admin/employees/'.$feedback->employee_id, 'actor_name' => $author->name,
                ]);
            }
        }
    }
}
