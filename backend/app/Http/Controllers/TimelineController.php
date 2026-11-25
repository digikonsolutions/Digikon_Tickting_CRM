<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\TimelineReview;
use App\Services\WorkItemScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The timeline review API. Three audiences share one payload shape:
 *  - admin: the review queue and every action, any project;
 *  - employee: only their own assigned work, only while the assignment is active;
 *  - agency owner / client: read-only plus replying to a change request, only their own agency (a client only
 *    the projects they created). Anything else is a 404, never a 403, so ids reveal nothing.
 */
class TimelineController extends Controller
{
    private const OPEN = ['scheduled', 'in_progress'];

    public function __construct(private TimelineReview $review) {}

    /* ---------------------------------------------------------------- admin */

    private function pendingQuery()
    {
        return WorkItem::query()->whereIn('status', self::OPEN)->where(function ($query): void {
            $query->whereIn('timeline_status', TimelineReview::PENDING)
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('tickets')->whereColumn('tickets.work_item_id', 'work_items.id')->whereIn('tickets.timeline_status', TimelineReview::PENDING));
        });
    }

    public function queue(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', 'in:proposed,in_review,changes_requested'],
            'agency' => ['sometimes', 'nullable', 'integer'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $rows = $this->pendingQuery()->with(['agency', 'client', 'employee', 'latestTimelineRequest', 'services'])
            ->withCount(['tickets as pending_tickets' => fn ($q) => $q->whereIn('timeline_status', TimelineReview::PENDING)])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('timeline_status', $status))
            ->when($filters['agency'] ?? null, fn ($q, $agency) => $q->where('agency_id', $agency))
            ->when($search !== '', function ($q) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)
                    ->orWhereHas('agency', fn ($a) => $a->where('name', 'like', $like))
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $like)));
            })
            // Urgent requests (a project or a waiting ticket marked urgent) lead the queue, then the longest waiting first.
            ->orderByRaw("CASE WHEN brief_priority = 'urgent' OR EXISTS (SELECT 1 FROM tickets WHERE tickets.work_item_id = work_items.id AND tickets.priority = 'urgent' AND tickets.timeline_status IN ('proposed','in_review','changes_requested')) THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(timeline_proposed_at, created_at) ASC')->orderBy('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'reviews' => collect($rows->items())->map(fn (WorkItem $p): array => [
                'id' => $p->id, 'title' => $p->title, 'agency_name' => $p->agency?->name, 'client_name' => $p->client?->name,
                'employee_name' => $p->employee?->name, 'services' => $p->services->map(fn (\App\Models\ProjectService $s): string => $s->label())->values(), 'estimated_hours' => (float) $p->estimated_hours, 'awaiting_schedule' => $p->isAwaitingSchedule(),
                'priority_urgent' => $p->brief_priority === 'urgent' || $p->tickets()->where('priority', 'urgent')->whereIn('timeline_status', TimelineReview::PENDING)->exists(),
                'start_date' => $p->start_date?->toDateString(), 'due_date' => $p->due_date?->toDateString(),
                'timeline_status' => $p->timeline_status, 'pending_tickets' => (int) $p->pending_tickets,
                'project_manager_id' => $p->project_manager_id, ...$this->age($p),
                'requester' => $this->requester($p),
            ])->values(),
            'pagination' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
            'summary' => $this->summary(),
        ]);
    }

    /** What the sidebar badge, the dashboard card and the reports need: how many wait, how many are late, how fast reviews go. */
    public function summary(): array
    {
        $pending = $this->pendingQuery()->get(['id', 'timeline_proposed_at', 'created_at', 'start_date', 'timeline_status']);
        $overdue = $pending->filter(fn (WorkItem $p): bool => $this->age($p)['overdue'])->count();
        $reviewed = DB::table('work_items')->whereNotNull('timeline_confirmed_at')->whereNotNull('timeline_proposed_at')->get(['timeline_proposed_at', 'timeline_confirmed_at']);
        $average = $reviewed->isEmpty() ? null : round($reviewed->avg(fn ($r): float => CarbonImmutable::parse($r->timeline_proposed_at)->diffInMinutes(CarbonImmutable::parse($r->timeline_confirmed_at)) / 60), 1);
        $changes = TimelineEvent::query()->where('type', 'changes_requested')->count();
        $proposals = TimelineEvent::query()->where('type', 'proposed')->count();

        return [
            'pending' => $pending->count(), 'overdue' => $overdue, 'average_review_hours' => $average,
            'changes_requested_rate' => $proposals > 0 ? round($changes / $proposals * 100) : null,
        ];
    }

    public function count(): JsonResponse
    {
        $summary = $this->summary();

        return response()->json(['pending' => $summary['pending'], 'overdue' => $summary['overdue']]);
    }

    public function adminShow(Request $request, int $workItem): JsonResponse
    {
        return response()->json($this->payload(WorkItem::query()->findOrFail($workItem), $request->user()));
    }

    public function adminStartReview(Request $request, int $workItem): JsonResponse
    {
        $project = $this->review->startReview(WorkItem::query()->findOrFail($workItem), $request->user());

        return response()->json($this->payload($project->fresh(), $request->user()));
    }

    public function adminRequestChanges(Request $request, int $workItem): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:3', 'max:2000']]);
        $project = $this->review->requestChanges(WorkItem::query()->findOrFail($workItem), $request->user(), trim($data['message']));

        return response()->json($this->payload($project->fresh(), $request->user()));
    }

    /* ------------------------------------------------- edit / confirm (admin and employee) */

    public function update(Request $request, int $workItem): JsonResponse
    {
        $data = $request->validate([
            'days' => ['required', 'array', 'min:1', 'max:200'],
            'days.*' => ['array'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $project = $this->review->editDays(WorkItem::query()->findOrFail($workItem), $request->user(), $data['days'], $data['due_date'] ?? null, $data['note'] ?? null);

        return response()->json($this->payload($project->fresh(), $request->user()));
    }

    public function confirm(Request $request, int $workItem): JsonResponse
    {
        $project = $this->review->confirm(WorkItem::query()->findOrFail($workItem), $request->user());

        return response()->json($this->payload($project->fresh(), $request->user()));
    }

    /* ----------------------------------------------------------- employee */

    public function employeeShow(Request $request, int $workItem): JsonResponse
    {
        $project = $this->employeeProject($request->user(), $workItem);

        return response()->json($this->payload($project, $request->user()));
    }

    /** Employees reach edit and confirm through the same actions, but only on their own active work. */
    public function employeeProject(User $user, int $id): WorkItem
    {
        $project = WorkItem::query()->where('employee_id', $user->id)->findOrFail($id);
        abort_unless($project->assignment()->where('status', 'active')->exists(), 404);

        return $project;
    }

    public function employeeUpdate(Request $request, int $workItem): JsonResponse
    {
        $this->employeeProject($request->user(), $workItem);

        return $this->update($request, $workItem);
    }

    public function employeeConfirm(Request $request, int $workItem): JsonResponse
    {
        $this->employeeProject($request->user(), $workItem);

        return $this->confirm($request, $workItem);
    }

    public function employeeAwaiting(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = $this->pendingQuery()->with(['agency', 'client'])->where('employee_id', $user->id)
            ->whereHas('assignment', fn ($q) => $q->where('status', 'active'))->orderBy('start_date')->get();

        return response()->json(['awaiting' => $rows->map(fn (WorkItem $p): array => [
            'id' => $p->id, 'title' => $p->title, 'agency_name' => $p->agency?->name, 'timeline_status' => $p->timeline_status,
            'estimated_hours' => (float) $p->estimated_hours, 'start_date' => $p->start_date?->toDateString(), 'due_date' => $p->due_date?->toDateString(),
        ])->values()]);
    }

    /* ------------------------------------------------------------- agency */

    private function agencyProject(User $user, int $id): WorkItem
    {
        return WorkItem::query()->where('agency_id', $user->agency_id)
            ->when($user->role === Role::AgencyClient, fn ($q) => $q->where('client_id', $user->id))->findOrFail($id);
    }

    public function agencyShow(Request $request, int $workItem): JsonResponse
    {
        return response()->json($this->payload($this->agencyProject($request->user(), $workItem), $request->user()));
    }

    public function respond(Request $request, int $workItem): JsonResponse
    {
        $project = $this->agencyProject($request->user(), $workItem);
        $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:2000'], 'files' => ['sometimes', 'array', 'max:'.(int) config('attachments.max_files_per_upload')], 'files.*' => ['file']]);
        $files = array_values($request->file('files', []));
        $errors = [];
        foreach ($files as $i => $file) {
            $problem = app(\App\Services\Attachments\AttachmentService::class)->problem($file, (bool) config('attachments.brief_allow_archives'));
            if ($problem !== null) {
                $errors["files.{$i}"] = [$problem];
            }
        }
        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
        $project = $this->review->respond($project, $request->user(), $request->input('note'), $files);

        return response()->json($this->payload($project->fresh(), $request->user()));
    }

    /* ------------------------------------------------------------ payload */

    /** @return array{age_hours: int, overdue: bool, urgent: bool} */
    private function age(WorkItem $project): array
    {
        $since = $project->timeline_proposed_at ?? $project->created_at;
        $hours = (int) CarbonImmutable::parse($since)->diffInHours(CarbonImmutable::now());
        $pending = in_array($project->timeline_status, TimelineReview::PENDING, true) || ($project->timeline_status === 'confirmed' && $project->timeline_proposed_at !== null && $this->hasPendingTickets($project));
        $startsSoon = $project->start_date !== null && CarbonImmutable::parse($project->start_date)->startOfDay()->lessThanOrEqualTo(CarbonImmutable::now()->addHours(24));

        return [
            'age_hours' => $hours,
            'overdue' => $pending && $hours >= (int) config('scheduling.review_overdue_hours', 24),
            'urgent' => $pending && $startsSoon,
        ];
    }

    private function hasPendingTickets(WorkItem $project): bool
    {
        return $project->tickets()->whereIn('timeline_status', TimelineReview::PENDING)->exists();
    }

    /** @return array{name: string, role: string, is_reply: bool, note: string|null}|null */
    private function requester(WorkItem $project): ?array
    {
        $event = $project->relationLoaded('latestTimelineRequest')
            ? $project->latestTimelineRequest
            : $project->latestTimelineRequest()->first();

        if ($event === null || $event->actor_name === null || $event->actor_role === null) {
            return null;
        }

        return [
            'name' => $event->actor_name,
            'role' => $event->actor_role,
            'is_reply' => (bool) ($event->after['response'] ?? false),
            'note' => $event->note,
        ];
    }

    /** @return array<string, mixed> */
    public function payload(WorkItem $project, User $viewer): array
    {
        $project->loadMissing(['agency', 'client', 'employee', 'latestTimelineRequest', 'services']);
        $employee = $project->employee;
        $own = collect($this->review->days($project))->keyBy('date');
        $from = collect([$project->start_date?->toDateString(), $own->keys()->first()])->filter()->min() ?? now()->toDateString();
        $to = collect([$project->due_date?->toDateString(), $own->keys()->last()])->filter()->max() ?? $from;
        $to = CarbonImmutable::parse($from)->addDays(120)->lessThan(CarbonImmutable::parse($to)) ? CarbonImmutable::parse($from)->addDays(120)->toDateString() : $to;
        $today = now(config('app.timezone'))->toDateString();
        $days = [];
        if ($employee !== null) {
            $others = $this->review->othersBooked($project, $employee);
            $cap = WorkItemScheduler::dailyCapacityUnits();
            foreach ((new WorkItemScheduler)->dailyAvailability($employee, CarbonImmutable::parse($from), CarbonImmutable::parse($to)) as $day) {
                $other = $others[$day['date']] ?? 0;
                $days[] = [
                    'date' => $day['date'], 'hours' => $own->get($day['date'])['hours'] ?? 0,
                    'other_booked_hours' => round($other / 100, 2), 'free_hours' => round(max(0, $cap - $other) / 100, 2),
                    'working_day' => $day['working_day'], 'reason' => $day['reason'], 'past' => $day['date'] < $today,
                ];
            }
        }
        $isAdmin = $viewer->role === Role::Admin;
        $pending = $this->review->isPending($project);
        $changesWaiting = $project->timeline_status === 'changes_requested' || $project->tickets()->where('timeline_status', 'changes_requested')->exists();
        $manager = $project->project_manager_id ? User::query()->find($project->project_manager_id, ['id', 'name']) : null;
        $confirmedBy = $project->timeline_confirmed_by ? User::query()->find($project->timeline_confirmed_by, ['id', 'name', 'role']) : null;
        $canEdit = $this->review->canEdit($viewer, $project) && in_array($project->status, self::OPEN, true);
        // Agency users see internal staff only as "{agency} Support": no admin name, id or role.
        $support = $viewer->role->isAgencyRole() ? \App\Services\AgencyBrandingPresenter::displayName($project->agency).' Support' : null;

        return [
            'project' => [
                'id' => $project->id, 'title' => $project->title, 'description' => $project->description, 'status' => $project->status,
                'estimated_hours' => (float) $project->estimated_hours, 'awaiting_schedule' => $project->isAwaitingSchedule(), 'schedule_state' => $project->scheduleState(), 'priority' => $project->brief_priority, 'planned_hours' => $project->scheduledHours(), 'hours_differ' => abs($project->scheduledHours() - (float) $project->estimated_hours) >= 0.005, 'planned_note' => $project->planned_note,
                'start_date' => $project->start_date?->toDateString(), 'due_date' => $project->due_date?->toDateString(),
                'agency' => ['id' => $project->agency_id, 'name' => $support && $project->agency ? \App\Services\AgencyBrandingPresenter::displayName($project->agency) : $project->agency?->name], 'client' => $project->client ? ['id' => $project->client->id, 'name' => $project->client->name] : null,
                'employee' => $employee ? ['id' => $employee->id, 'name' => $employee->name] : null,
                // What was asked for (each service with how many questions were answered) and the hours against the tickets.
                'services' => $project->services->map(fn (\App\Models\ProjectService $s): array => ['id' => $s->id, 'key' => $s->service_key, 'label' => $s->label(), 'estimated_hours' => $s->estimated_hours !== null ? (float) $s->estimated_hours : null, 'details' => $s->details ?? []])->values(),
                'hours' => app(\App\Services\ProjectHours::class)->summary($project),
            ],
            'timeline' => [
                'status' => $project->timeline_status, 'pending' => $pending, 'proposed_at' => $project->timeline_proposed_at?->toISOString(),
                'confirmed_at' => $project->timeline_confirmed_at?->toISOString(),
                'confirmed_by' => $confirmedBy && $support && $confirmedBy->role === Role::Admin ? $support : $confirmedBy?->name,
                'project_manager' => $manager ? ($support ? ['id' => null, 'name' => $support] : ['id' => $manager->id, 'name' => $manager->name]) : null,
                'requester' => $this->requester($project),
                ...$this->age($project),
                'can_edit' => $canEdit, 'can_confirm' => $canEdit && $pending && ! ($viewer->role === Role::Employee && $project->timeline_status === 'changes_requested'),
                'can_request_changes' => $isAdmin && $pending && in_array($project->status, self::OPEN, true),
                'can_respond' => $changesWaiting && in_array($viewer->role, [Role::AgencyOwner, Role::AgencyClient], true),
            ],
            'capacity' => WorkItemScheduler::capacity(),
            'days' => $days,
            'tickets' => $project->tickets()->with('projectService')->orderBy('position')->orderBy('id')->get()->map(fn (Ticket $t): array => [
                'id' => $t->id, 'key' => $t->key, 'title' => $t->title, 'status' => $t->status, 'timeline_status' => $t->timeline_status, 'service' => $t->projectService?->label(),
                'estimated_hours' => (float) $t->estimated_hours, 'start_date' => $t->start_date?->toDateString(), 'due_date' => $t->due_date?->toDateString(),
            ])->values(),
            'attachments' => $project->attachments()->orderBy('id')->get()->map(fn (Attachment $a): array => [
                'id' => $a->id, 'name' => $a->original_name, 'size_bytes' => $a->size, 'download_url' => "/api/project-attachments/{$a->id}/download",
            ])->values(),
            'events' => TimelineEvent::query()->where('work_item_id', $project->id)->orderByDesc('id')->limit(100)->get()->map(fn (TimelineEvent $e): array => [
                'id' => $e->id, 'type' => $e->type, 'subject' => $e->subject_type,
                'actor' => $support && in_array($e->actor_role, ['admin', 'team_lead', 'qa'], true) ? $support : $e->actor_name,
                'actor_role' => $support && in_array($e->actor_role, ['admin', 'team_lead', 'qa'], true) ? 'support' : $e->actor_role,
                'note' => $e->note, 'before' => $e->before, 'after' => $e->after, 'created_at' => $e->created_at?->toISOString(),
            ])->values(),
        ];
    }
}
