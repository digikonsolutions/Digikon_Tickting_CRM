<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ListAdminTicketsRequest;
use App\Http\Requests\ReorderTicketsRequest;
use App\Http\Requests\StoreTicketCommentRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Requests\UpdateTicketStatusRequest;
use App\Models\Agency;
use App\Models\EmployeeAssignment;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\TicketEventRecorder;
use App\Services\TicketPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketController extends Controller
{
    private const COMMENT_LIMIT = 200;

    public function index(Request $request, WorkItem $workItem): JsonResponse
    {
        $this->authorizeProject($request->user(), $workItem);

        return response()->json([
            'tickets' => $workItem->tickets()
                ->with(['allocations', 'creator'])
                ->withCount('comments')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (Ticket $ticket): array => $this->ticketPayload($ticket))
                ->values(),
        ]);
    }

    public function store(
        StoreTicketRequest $request,
        WorkItem $workItem,
        TicketPlanner $planner,
        TicketEventRecorder $recorder
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeProject($user, $workItem, requireOpen: true, requireActiveAssignment: true);
        $data = $request->validated();

        $ticket = DB::transaction(function () use ($workItem, $user, $data, $planner, $recorder): Ticket {
            $this->lockActiveProjectAssignment($workItem);
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($lockedWorkItem);

            $agency = Agency::query()->lockForUpdate()->findOrFail($lockedWorkItem->agency_id);
            $nextNumber = $agency->ticket_sequence + 1;
            $agency->update(['ticket_sequence' => $nextNumber]);

            $position = ((int) $lockedWorkItem->tickets()->max('position')) + 1;

            $ticket = $lockedWorkItem->tickets()->create([
                ...$data,
                'agency_id' => $agency->id,
                'number' => $nextNumber,
                'created_by' => $user->id,
                'position' => $position,
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'todo',
            ]);

            $planner->plan($lockedWorkItem, $ticket->id, [
                'type' => 'ticket_created',
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->key,
                'actor' => $user,
                'actor_name' => $user->name,
            ]);
            $recorder->record($ticket, 'created', [], $user);

            return $ticket;
        });

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh()->load(['allocations', 'creator'])),
        ], 201);
    }

    public function update(
        UpdateTicketRequest $request,
        Ticket $ticket,
        TicketPlanner $planner,
        TicketEventRecorder $recorder
    ): JsonResponse {
        $user = $request->user();
        $workItem = $ticket->workItem;
        $this->authorizeProject($user, $workItem, requireOpen: true, requireActiveAssignment: true);
        $data = $request->validated();

        $rescheduled = [];
        DB::transaction(function () use ($ticket, $workItem, $data, $planner, $user, $recorder, &$rescheduled): void {
            $this->lockActiveProjectAssignment($workItem);
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($lockedWorkItem);
            $lockedTicket = $lockedWorkItem->tickets()->lockForUpdate()->findOrFail($ticket->id);

            $changes = [];
            foreach (['title', 'description', 'estimated_hours', 'priority'] as $field) {
                if (array_key_exists($field, $data)) {
                    $oldVal = $lockedTicket->{$field};
                    $newVal = $data[$field];
                    if ($field === 'estimated_hours') {
                        $oldVal = number_format((float) $oldVal, 2, '.', '');
                        $newVal = number_format((float) $newVal, 2, '.', '');
                    }
                    if ((string) $oldVal !== (string) $newVal) {
                        $changes[$field] = ['from' => $oldVal, 'to' => $newVal];
                    }
                }
            }

            $lockedTicket->update($data);

            if (! empty($changes)) {
                $recorder->record($lockedTicket, 'updated', ['changes' => $changes], $user);
            }

            $rescheduled = $planner->plan($lockedWorkItem, $lockedTicket->id, [
                'type' => 'ticket_updated',
                'ticket_id' => $lockedTicket->id,
                'ticket_key' => $lockedTicket->key,
                'actor' => $user,
                'actor_name' => $user->name,
            ]);
        });

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh()->load(['allocations', 'creator'])),
            'rescheduled' => $rescheduled,
        ]);
    }

    public function cancel(
        Request $request,
        Ticket $ticket,
        TicketPlanner $planner,
        TicketEventRecorder $recorder
    ): JsonResponse {
        $workItem = $ticket->workItem;
        $user = $request->user();
        $this->authorizeProject($user, $workItem, requireOpen: true, requireActiveAssignment: true);

        abort_unless(! in_array($ticket->status, ['done', 'cancelled'], true), 422, 'This ticket is already closed.');

        DB::transaction(function () use ($ticket, $workItem, $planner, $user, $recorder): void {
            $this->lockActiveProjectAssignment($workItem);
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($lockedWorkItem);
            $lockedTicket = $lockedWorkItem->tickets()->lockForUpdate()->findOrFail($ticket->id);
            abort_unless(! in_array($lockedTicket->status, ['done', 'cancelled'], true), 422, 'This ticket is already closed.');
            $lockedTicket->update(['status' => 'cancelled']);
            $recorder->record($lockedTicket, 'cancelled', ['cause' => 'ticket'], $user);
            $planner->plan($lockedWorkItem, null, [
                'type' => 'ticket_cancelled',
                'ticket_id' => $lockedTicket->id,
                'ticket_key' => $lockedTicket->key,
                'actor' => $user,
                'actor_name' => $user->name,
            ]);
        });

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh()->load(['allocations', 'creator'])),
        ]);
    }

    public function reorder(
        ReorderTicketsRequest $request,
        WorkItem $workItem,
        TicketPlanner $planner,
        TicketEventRecorder $recorder
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeProject($user, $workItem, requireOpen: true, requireActiveAssignment: true);
        $ids = $request->validated('ticket_ids');

        $rescheduled = [];
        DB::transaction(function () use ($workItem, $ids, $planner, $user, $recorder, &$rescheduled): void {
            $this->lockActiveProjectAssignment($workItem);
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($lockedWorkItem);
            $tickets = $lockedWorkItem->tickets()->lockForUpdate()->get();
            $actualIds = $tickets->pluck('id')->sort()->values()->all();
            $orderedIds = collect($ids)->map(fn ($id): int => (int) $id)->all();

            if (count($actualIds) !== count($orderedIds) || $actualIds !== collect($orderedIds)->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'ticket_ids' => 'The ordered ticket IDs must include every ticket in this project exactly once.',
                ]);
            }

            foreach ($orderedIds as $position => $ticketId) {
                $t = $tickets->firstWhere('id', $ticketId);
                $t->update(['position' => $position]);
                $recorder->record($t, 'reordered', [], $user);
            }

            $prioritisedTicket = $tickets->firstWhere('id', $orderedIds[0]);
            $rescheduled = $planner->plan($lockedWorkItem, null, [
                'type' => 'reordered',
                'ticket_id' => $prioritisedTicket?->id,
                'ticket_key' => $prioritisedTicket?->key,
                'actor' => $user,
                'actor_name' => $user->name,
            ]);
        });

        $indexResponse = $this->index($request, $workItem->fresh());
        $responseData = $indexResponse->getData(true);
        $responseData['rescheduled'] = $rescheduled;

        return response()->json($responseData);
    }

    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user();
        $this->ensureActiveEmployeeAgency($employee);
        $tickets = Ticket::query()
            ->with(['allocations', 'creator', 'workItem'])
            ->withCount('comments')
            ->whereHas('workItem', fn ($query) => $query->where('employee_id', $employee->id))
            ->orderBy('work_item_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Ticket $ticket): array => $this->ticketPayload($ticket));

        return response()->json(['tickets' => $tickets]);
    }

    public function updateStatus(
        UpdateTicketStatusRequest $request,
        Ticket $ticket,
        TicketPlanner $planner,
        TicketEventRecorder $recorder
    ): JsonResponse {
        $employee = $request->user();
        $this->ensureActiveEmployeeAgency($employee);
        $workItem = $ticket->workItem;
        abort_unless($workItem->employee_id === $employee->id, 404);
        $this->ensureOpenProject($workItem);
        $this->ensureActiveProjectAssignment($workItem);
        $newStatus = $request->validated('status');
        $transitions = [
            'todo' => 'in_progress',
            'in_progress' => 'in_review',
            'in_review' => 'done',
        ];
        abort_unless(($transitions[$ticket->status] ?? null) === $newStatus, 422, 'This ticket status transition is not allowed.');

        DB::transaction(function () use ($ticket, $workItem, $newStatus, $planner, $employee, $recorder): void {
            $this->lockActiveProjectAssignment($workItem);
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($lockedWorkItem);
            $lockedTicket = $lockedWorkItem->tickets()->lockForUpdate()->findOrFail($ticket->id);
            $transitions = [
                'todo' => 'in_progress',
                'in_progress' => 'in_review',
                'in_review' => 'done',
            ];
            abort_unless(($transitions[$lockedTicket->status] ?? null) === $newStatus, 422, 'This ticket status transition is not allowed.');
            $oldStatus = $lockedTicket->status;
            $lockedTicket->update(['status' => $newStatus]);
            $recorder->record($lockedTicket, 'status_changed', [
                'from' => $oldStatus,
                'to' => $newStatus,
                'action' => $newStatus,
            ], $employee);

            if ($newStatus === 'in_progress' && $lockedWorkItem->status === 'scheduled') {
                $lockedWorkItem->update(['status' => 'in_progress']);
            }

            $hasOpenTickets = $lockedWorkItem->tickets()
                ->whereNotIn('status', ['done', 'cancelled'])
                ->exists();
            $hasDoneTickets = $lockedWorkItem->tickets()->where('status', 'done')->exists();

            if (! $hasOpenTickets && $hasDoneTickets) {
                $lockedWorkItem->update(['status' => 'completed']);
            }

            $planner->plan($lockedWorkItem, null, [
                'type' => 'status_changed',
                'ticket_id' => $lockedTicket->id,
                'ticket_key' => $lockedTicket->key,
                'actor' => $employee,
                'actor_name' => $employee->name,
            ]);
        });

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh()->load(['allocations', 'creator'])),
        ]);
    }

    public function adminIndex(ListAdminTicketsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $tickets = Ticket::query()
            ->with(['workItem.agency', 'creator'])
            ->withCount('comments')
            ->orderBy('work_item_id')
            ->orderBy('position')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'tickets' => collect($tickets->items())->map(fn (Ticket $ticket): array => [
                ...$this->ticketPayload($ticket),
                'project_title' => $ticket->workItem->title,
                'agency_name' => $ticket->workItem->agency?->name,
            ]),
            'pagination' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function comment(
        StoreTicketCommentRequest $request,
        Ticket $ticket,
        TicketEventRecorder $recorder
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeComments($user, $ticket);

        $isInternal = (bool) ($request->validated('is_internal') ?? false);
        if ($isInternal) {
            abort_unless(
                in_array($user->role, [Role::Admin, Role::Employee], true),
                403,
                'Only employees and administrators can create internal comments.'
            );
        }

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $request->validated('body'),
            'is_internal' => $isInternal,
        ]);

        $recorder->record(
            $ticket,
            'comment',
            ['comment_id' => $comment->id],
            $user,
            $isInternal ? 'internal' : 'public'
        );

        return response()->json(['comment' => $this->commentPayload($comment->setRelation('user', $user))], 201);
    }

    /**
     * Latest 200 comments, oldest first, plus the total number of comments on the ticket.
     */
    public function comments(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeComments($request->user(), $ticket);

        $user = $request->user();
        $canViewInternal = $user->role === Role::Admin
            || ($user->role === Role::Employee && $ticket->workItem->employee_id === $user->id);

        $commentsQuery = $ticket->comments()
            ->with('user')
            ->when(! $canViewInternal, fn ($query) => $query->where('is_internal', false));

        $comments = (clone $commentsQuery)
            ->orderByDesc('id')
            ->limit(self::COMMENT_LIMIT)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'comments' => $comments->map(fn (TicketComment $comment): array => $this->commentPayload($comment))->values(),
            'total' => $commentsQuery->count(),
        ]);
    }

    /**
     * One rule for reading and writing comments: admins, the ticket's assigned employee, and
     * agency owners/clients of the ticket's (active) agency.
     */
    private function authorizeComments(User $user, Ticket $ticket): void
    {
        $workItem = $ticket->workItem;
        $isAdmin = $user->role === Role::Admin;
        $isAssignedEmployee = $user->role === Role::Employee && $workItem->employee_id === $user->id;
        $isAgencyParticipant = in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)
            && $this->canAccessProject($user, $workItem);
        abort_unless($isAdmin || $isAssignedEmployee || $isAgencyParticipant, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function commentPayload(TicketComment $comment): array
    {
        return [
            'id' => $comment->id,
            'ticket_id' => $comment->ticket_id,
            'user' => [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
                'role' => $comment->user->role->value,
            ],
            'body' => $comment->body,
            'is_internal' => (bool) $comment->is_internal,
            'created_at' => $comment->created_at?->toISOString(),
            'updated_at' => $comment->updated_at?->toISOString(),
        ];
    }

    private function authorizeProject(
        User $user,
        WorkItem $workItem,
        bool $requireOpen = false,
        bool $requireActiveAssignment = false
    ): void {
        abort_unless($this->canAccessProject($user, $workItem), 404);

        if ($requireOpen) {
            $this->ensureOpenProject($workItem);
        }

        if ($requireActiveAssignment) {
            $this->ensureActiveProjectAssignment($workItem);
        }
    }

    private function canAccessProject(User $user, WorkItem $workItem): bool
    {
        if (
            ! in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)
            || $user->agency_id === null
            || $user->agency?->status !== 'active'
            || $workItem->agency_id !== $user->agency_id
        ) {
            return false;
        }

        return true;
    }

    private function ensureOpenProject(WorkItem $workItem): void
    {
        abort_unless(in_array($workItem->status, ['scheduled', 'in_progress'], true), 422, 'Tickets can only be changed on open projects.');
    }

    private function ensureActiveProjectAssignment(WorkItem $workItem): void
    {
        abort_unless(
            EmployeeAssignment::query()
                ->whereKey($workItem->assignment_id)
                ->where('employee_id', $workItem->employee_id)
                ->where('agency_id', $workItem->agency_id)
                ->where('status', 'active')
                ->exists(),
            404
        );
    }

    private function lockActiveProjectAssignment(WorkItem $workItem): EmployeeAssignment
    {
        $assignment = EmployeeAssignment::query()
            ->whereKey($workItem->assignment_id)
            ->where('employee_id', $workItem->employee_id)
            ->where('agency_id', $workItem->agency_id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        abort_unless($assignment !== null, 404);

        return $assignment;
    }

    private function ensureActiveEmployeeAgency(User $user): void
    {
        if ($user->agency_id !== null) {
            abort_unless($user->agency?->status === 'active', 403, 'An active agency is required to access employee tickets.');
        }
    }

    public function showByKey(Request $request, string $key): JsonResponse
    {
        $lastHyphenPos = strrpos($key, '-');
        abort_if($lastHyphenPos === false, 404);

        $prefix = substr($key, 0, $lastHyphenPos);
        $numberStr = substr($key, $lastHyphenPos + 1);
        abort_unless(ctype_digit($numberStr), 404);

        $agency = Agency::query()->where('ticket_prefix', $prefix)->first();
        abort_unless($agency !== null, 404);

        $ticket = Ticket::query()
            ->with(['agency', 'allocations', 'creator', 'workItem.agency'])
            ->where('agency_id', $agency->id)
            ->where('number', (int) $numberStr)
            ->first();

        abort_unless($ticket !== null, 404);

        $this->authorizeTicketView($request->user(), $ticket);

        $project = $ticket->workItem;

        return response()->json([
            'ticket' => $this->ticketPayload($ticket),
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'status' => $project->status,
                'agency_name' => $project->agency?->name,
            ],
            'timeline' => [],
        ]);
    }

    public function authorizeTicketView(User $user, Ticket $ticket): void
    {
        if ($user->role === Role::Admin) {
            return;
        }

        $workItem = $ticket->workItem;

        if ($user->role === Role::Employee && $workItem->employee_id === $user->id) {
            if ($user->agency_id !== null && $user->agency?->status !== 'active') {
                abort(404);
            }

            return;
        }

        if (
            in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)
            && $user->agency_id !== null
            && $user->agency_id === $ticket->agency_id
            && $user->agency?->status === 'active'
        ) {
            return;
        }

        abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketPayload(Ticket $ticket): array
    {
        $ticket->loadMissing(['agency', 'allocations', 'creator']);

        return [
            'id' => $ticket->id,
            'key' => $ticket->key,
            'number' => $ticket->number,
            'work_item_id' => $ticket->work_item_id,
            'created_by' => $ticket->created_by,
            'creator' => $ticket->creator ? ['id' => $ticket->creator->id, 'name' => $ticket->creator->name] : null,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'estimated_hours' => $ticket->estimated_hours,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'position' => $ticket->position,
            'start_date' => $ticket->start_date?->toDateString(),
            'due_date' => $ticket->due_date?->toDateString(),
            'comments_count' => (int) (array_key_exists('comments_count', $ticket->getAttributes())
                ? $ticket->comments_count
                : $ticket->comments()->count()),
            'schedule' => $ticket->allocations
                ->sortBy('work_date')
                ->map(fn ($allocation): array => [
                    'work_date' => $allocation->work_date->toDateString(),
                    'hours' => $allocation->hours,
                ])->values(),
        ];
    }
}
