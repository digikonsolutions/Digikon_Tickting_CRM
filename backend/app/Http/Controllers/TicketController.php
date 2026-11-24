<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Controllers\Concerns\GuardsProjectAccess;
use App\Http\Requests\DuplicateTicketRequest;
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
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\ActivityLogger;
use App\Services\Attachments\AttachmentPresenter;
use App\Services\TicketAccess;
use App\Services\TicketDetailsService;
use App\Services\TicketEventRecorder;
use App\Services\TicketPlanner;
use App\Services\TicketWorkflow;
use App\Services\TimeTracker;
use App\Services\WorkflowNotificationService;
use App\Services\WorkItemScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketController extends Controller
{
    use GuardsProjectAccess;

    private const COMMENT_LIMIT = 200;

    public function index(Request $request, WorkItem $workItem): JsonResponse
    {
        $this->authorizeProject($request->user(), $workItem);

        return response()->json([
            'tickets' => $workItem->tickets()
                ->with(['agency', 'allocations', 'creator', 'latestStatusEvent', 'pendingHoursRequest', 'workItem.assignment', 'detail'])
                ->withCount(['comments' => $this->commentVisibility($request->user())])
                ->withSum(['timeEntries as logged_minutes' => fn ($query) => $query->whereNotNull('minutes')], 'minutes')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (Ticket $ticket): array => $this->ticketPayload($ticket, $request->user()))
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
        // New tickets are allowed on completed projects too (follow-up work). A ticket added to an
        // open project never changes its status; one added to a completed project reopens it (see
        // reopenCompletedProjectForFollowUp) so the new ticket actually gets real, future work days
        // instead of being squeezed into the project's already-past schedule window.
        $this->authorizeProject($user, $workItem, requireOpen: true, requireActiveAssignment: true, allowCompleted: true);
        $data = $request->validated();
        $order = $data['work_order'] ?? null;
        unset($data['work_order']);

        return $this->createTicket($workItem, $user, $data, $order, $planner, $recorder);
    }

    /**
     * A copy of a ticket in the same project: same text, hours and work order (the checklist starts undone; delivery,
     * blocked state and review are not copied). It is planned like any new ticket, so the project's hours and the
     * employee's capacity apply.
     */
    public function duplicate(
        DuplicateTicketRequest $request,
        WorkItem $workItem,
        Ticket $ticket,
        TicketPlanner $planner,
        TicketEventRecorder $recorder,
        TicketDetailsService $details
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeProject($user, $workItem, requireOpen: true, requireActiveAssignment: true, allowCompleted: true);
        abort_unless($ticket->work_item_id === $workItem->id, 404);
        $input = $request->validated();

        return $this->createTicket($workItem, $user, [
            'title' => $input['title'] ?? mb_substr('Copy of '.$ticket->title, 0, 160),
            'description' => $ticket->description,
            'estimated_hours' => $input['estimated_hours'] ?? $ticket->estimated_hours,
            'priority' => $ticket->priority,
            'service_type' => $ticket->service_type,
            'details' => $ticket->details,
        ], $details->copyOrder($ticket), $planner, $recorder);
    }

    /** @param  array<string, mixed>  $data  @param  array<string, mixed>|null  $order */
    private function createTicket(WorkItem $workItem, User $user, array $data, ?array $order, TicketPlanner $planner, TicketEventRecorder $recorder): JsonResponse
    {
        $ticket = DB::transaction(function () use ($workItem, $user, $data, $order, $planner, $recorder): Ticket {
            $this->lockActiveProjectAssignment($workItem);
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($lockedWorkItem, allowCompleted: true);

            if ($lockedWorkItem->status === 'completed') {
                $this->reopenCompletedProjectForFollowUp($lockedWorkItem, (float) $data['estimated_hours'], $user);
            }

            $agency = Agency::query()->lockForUpdate()->findOrFail($lockedWorkItem->agency_id);
            $nextNumber = $agency->ticket_sequence + 1;
            $agency->update(['ticket_sequence' => $nextNumber]);

            $position = ((int) $lockedWorkItem->tickets()->max('position')) + 1;

            $ticket = $lockedWorkItem->tickets()->create([
                ...$data,
                'agency_id' => $agency->id,
                'number' => $nextNumber,
                'ref' => app(\App\Services\Support\TicketNumberGenerator::class)->next(),
                'created_by' => $user->id,
                'position' => $position,
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'todo',
            ]);
            // The work order is information only: it never touches hours, position, status or the planner below.
            if ($order !== null) {
                app(TicketDetailsService::class)->save($ticket, $order);
            }

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

        // A new ticket is proposed on its own; a confirmed project and its confirmed tickets stay as they are.
        app(\App\Services\TimelineReview::class)->proposeTicket($ticket->fresh(), $workItem->fresh(), $user);

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh()->load(['agency', 'allocations', 'creator']), $user),
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
        abort_unless(! in_array($ticket->status, ['done', 'cancelled'], true), 422, 'This ticket is already closed.');
        $data = $request->validated();
        $order = $data['work_order'] ?? null;
        unset($data['work_order']);
        // Another service makes the old answers meaningless: they go unless new ones are sent with it.
        if (array_key_exists('service_type', $data) && $data['service_type'] !== $ticket->service_type && ! array_key_exists('details', $data)) {
            $data['details'] = null;
        }

        $rescheduled = [];
        DB::transaction(function () use ($ticket, $workItem, $data, $order, $planner, $user, $recorder, &$rescheduled): void {
            $this->lockActiveProjectAssignment($workItem);
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($lockedWorkItem);
            $lockedTicket = $lockedWorkItem->tickets()->lockForUpdate()->findOrFail($ticket->id);
            // Checked again under the lock: it may have been closed since the first check.
            abort_unless(! in_array($lockedTicket->status, ['done', 'cancelled'], true), 422, 'This ticket is already closed.');

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
            if ($order !== null) {
                app(TicketDetailsService::class)->save($lockedTicket, $order);
            }

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
            'ticket' => $this->ticketPayload($ticket->fresh()->load(['agency', 'allocations', 'creator']), $user),
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
            app(TimeTracker::class)->stopForTickets([$lockedTicket->id], 'ticket_cancelled');
            $planner->plan($lockedWorkItem, null, [
                'type' => 'ticket_cancelled',
                'ticket_id' => $lockedTicket->id,
                'ticket_key' => $lockedTicket->key,
                'actor' => $user,
                'actor_name' => $user->name,
            ]);
        });

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh()->load(['agency', 'allocations', 'creator']), $user),
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

            // Compare relative order (stored positions are not necessarily 0-based) so only tickets
            // that actually moved get a `reordered` event.
            $previousOrder = $tickets->sortBy([['position', 'asc'], ['id', 'asc']])->pluck('id')->values()->all();
            foreach ($orderedIds as $position => $ticketId) {
                $t = $tickets->firstWhere('id', $ticketId);
                $from = array_search($ticketId, $previousOrder, true);
                $t->update(['position' => $position]);
                if ($from !== $position) {
                    $recorder->record($t, 'reordered', ['from_position' => $from + 1, 'to_position' => $position + 1], $user);
                }
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
            ->with(['agency', 'allocations', 'creator', 'latestStatusEvent', 'workItem.assignment', 'pendingHoursRequest', 'detail'])
            ->withCount(['comments' => $this->commentVisibility($employee)])
            ->withSum(['timeEntries as logged_minutes' => fn ($query) => $query->whereNotNull('minutes')], 'minutes')
            ->whereHas('workItem', fn ($query) => $query->where('employee_id', $employee->id))
            ->orderBy('work_item_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Ticket $ticket): array => $this->ticketPayload($ticket, $employee));

        return response()->json(['tickets' => $tickets, 'capacity' => WorkItemScheduler::capacity(), 'clock' => WorkItemScheduler::clock()]);
    }

    public function updateStatus(
        UpdateTicketStatusRequest $request,
        Ticket $ticket,
        TicketWorkflow $workflow
    ): JsonResponse {
        $employee = $request->user();
        $this->ensureActiveEmployeeAgency($employee);
        $workItem = $ticket->workItem;
        abort_unless($workItem->employee_id === $employee->id, 404);
        $this->ensureOpenProject($workItem);
        $this->ensureActiveProjectAssignment($workItem);

        $newStatus = $request->validated('status');
        $action = match ($newStatus) {
            'in_progress' => match ($ticket->status) {
                'todo' => 'start',
                'blocked' => 'unblock',
                'changes_requested' => 'restart_work',
                default => null,
            },
            'in_review' => 'send_to_review',
            'done' => 'approve',
            default => null,
        };

        if ($action === null) {
            abort(422, 'This ticket status transition is not allowed.');
        }

        $updatedTicket = $workflow->transition($ticket, $action, null, $employee);

        return response()->json([
            'ticket' => $this->ticketPayload($updatedTicket, $employee),
        ]);
    }

    public function transition(
        Request $request,
        Ticket $ticket,
        TicketWorkflow $workflow
    ): JsonResponse {
        $data = $request->validate([
            'action' => ['required', 'string'],
            'comment' => ['nullable', 'string', 'max:10000'],
        ]);

        $updatedTicket = $workflow->transition(
            $ticket,
            $data['action'],
            $data['comment'] ?? null,
            $request->user()
        );

        return response()->json([
            'ticket' => $this->ticketPayload($updatedTicket, $request->user()),
        ]);
    }

    public function adminIndex(ListAdminTicketsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $tickets = Ticket::query()
            ->with(['agency', 'allocations', 'workItem.agency', 'workItem.assignment', 'creator', 'latestStatusEvent', 'pendingHoursRequest', 'detail'])
            ->withCount(['comments' => $this->commentVisibility($request->user())])
            ->withSum(['timeEntries as logged_minutes' => fn ($query) => $query->whereNotNull('minutes')], 'minutes')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['agency_id'] ?? null, fn ($query, $agencyId) => $query->where('agency_id', $agencyId))
            ->when($filters['employee_id'] ?? null, fn ($query, $employeeId) => $query->whereHas('workItem', fn ($q) => $q->where('employee_id', $employeeId)))
            ->when($filters['waiting_on'] ?? null, function ($query, $waitingOn): void {
                if ($waitingOn === 'client') {
                    $query->whereIn('status', ['in_review', 'waiting_on_client']);
                } elseif ($waitingOn === 'employee') {
                    $query->whereIn('status', ['todo', 'in_progress', 'changes_requested']);
                } elseif ($waitingOn === 'team') {
                    $query->where('status', 'blocked');
                }
            })
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('title', 'like', "%{$search}%");
                    $lastHyphenPos = strrpos($search, '-');
                    if ($lastHyphenPos !== false) {
                        $prefix = substr($search, 0, $lastHyphenPos);
                        $num = substr($search, $lastHyphenPos + 1);
                        if (ctype_digit($num)) {
                            $q->orWhere(function ($sub) use ($prefix, $num): void {
                                $sub->where('number', (int) $num)
                                    ->whereHas('agency', fn ($agencyQ) => $agencyQ->where('ticket_prefix', $prefix));
                            });
                        }
                    }
                });
            })
            ->orderBy('work_item_id')
            ->orderBy('position')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'tickets' => collect($tickets->items())->map(fn (Ticket $ticket): array => [
                ...$this->ticketPayload($ticket, $request->user()),
                'project_title' => $ticket->workItem?->title ?? $ticket->relatedProject?->title,
                'agency_name' => $ticket->workItem?->agency?->name ?? $ticket->agency?->name,
            ]),
            'pagination' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    /**
     * Every ticket across the caller's agency (owner) or across their own projects (client), for the Tickets page.
     * Filters: project_id, client_id (owner only), status, priority, waiting=you, search (title or key), page, per_page.
     */
    public function agencyIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate([
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string', 'in:todo,in_progress,in_review,waiting_on_client,changes_requested,blocked,done,cancelled'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:20'],
            'waiting' => ['sometimes', 'nullable', 'in:you'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $isOwner = $user->role === Role::AgencyOwner;
        $search = trim((string) ($filters['search'] ?? ''));

        $tickets = Ticket::query()
            ->with(['agency', 'allocations', 'workItem.agency', 'workItem.assignment', 'workItem.client', 'creator', 'latestStatusEvent', 'pendingHoursRequest', 'detail'])
            ->withCount(['comments' => $this->commentVisibility($user)])
            ->withSum(['timeEntries as logged_minutes' => fn ($query) => $query->whereNotNull('minutes')], 'minutes')
            ->where('agency_id', $user->agency_id)
            ->whereHas('workItem', fn ($q) => $q->where('agency_id', $user->agency_id)
                ->when(! $isOwner, fn ($q) => $q->where('client_id', $user->id))
                ->when(($filters['project_id'] ?? null) !== null, fn ($q) => $q->whereKey($filters['project_id']))
                ->when($isOwner && ($filters['client_id'] ?? null) !== null, fn ($q) => $q->where('client_id', $filters['client_id'])))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when(($filters['waiting'] ?? null) === 'you', fn ($query) => $query->whereIn('status', ['in_review', 'waiting_on_client']))
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(function ($q) use ($like, $search): void {
                    $q->where('title', 'like', $like);
                    $hyphen = strrpos($search, '-');
                    if ($hyphen !== false && ctype_digit(substr($search, $hyphen + 1))) {
                        $q->orWhere(fn ($sub) => $sub->where('number', (int) substr($search, $hyphen + 1))
                            ->whereHas('agency', fn ($a) => $a->where('ticket_prefix', substr($search, 0, $hyphen))));
                    }
                });
            })
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'tickets' => collect($tickets->items())->map(fn (Ticket $ticket): array => [
                ...$this->ticketPayload($ticket, $user),
                'project_id' => $ticket->work_item_id,
                'project_title' => $ticket->workItem?->title ?? $ticket->relatedProject?->title,
                'client_name' => $ticket->workItem?->client?->name ?? $ticket->submitter?->name,
            ])->values(),
            'pagination' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    /**
     * Tickets in the caller's agency that are waiting on the client (review or a question),
     * longest-waiting first. Limited to 5 for the dashboard card; `total` is the full count.
     */
    public function attention(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Ticket::query()
            ->where('agency_id', $user->agency_id)
            ->whereIn('status', ['in_review', 'waiting_on_client'])
            ->whereHas('workItem', fn ($q) => $q->whereIn('status', ['scheduled', 'in_progress']));

        $tickets = (clone $query)
            ->with(['agency', 'allocations', 'creator', 'latestStatusEvent', 'pendingHoursRequest', 'workItem.assignment', 'detail'])
            ->withCount(['comments' => $this->commentVisibility($user)])
            ->withSum(['timeEntries as logged_minutes' => fn ($query) => $query->whereNotNull('minutes')], 'minutes')
            ->orderBy(
                TicketEvent::query()
                    ->select('created_at')
                    ->whereColumn('ticket_events.ticket_id', 'tickets.id')
                    ->where('type', 'status_changed')
                    ->orderByDesc('id')
                    ->limit(1)
            )
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (Ticket $ticket): array => [
                ...$this->ticketPayload($ticket, $user),
                'project_title' => $ticket->workItem?->title ?? $ticket->relatedProject?->title,
            ])
            ->values();

        return response()->json(['tickets' => $tickets, 'total' => $query->count()]);
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
        app(ActivityLogger::class)->ticketCommented($user, $ticket, $isInternal);
        app(\App\Services\Support\SupportConversation::class)->afterComment($ticket, $user, $comment);

        return response()->json(['comment' => $this->commentPayload($comment->setRelation('user', $user), $user)], 201);
    }

    /**
     * Latest 200 comments, oldest first, plus the total number of comments on the ticket.
     */
    public function comments(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeComments($request->user(), $ticket);

        $user = $request->user();
        $canViewInternal = app(TicketAccess::class)->canSeeInternal($user, $ticket);

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
            'comments' => $comments->map(fn (TicketComment $comment): array => $this->commentPayload($comment, $request->user()))->values(),
            'total' => $commentsQuery->count(),
        ]);
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
            ->with(['agency', 'allocations', 'creator', 'latestStatusEvent', 'pendingHoursRequest', 'workItem.agency', 'workItem.assignment', 'workItem.ghlBrief', 'detail'])
            ->where('agency_id', $agency->id)
            ->where('number', (int) $numberStr)
            ->first();

        abort_unless($ticket !== null, 404);

        $this->authorizeTicketView($request->user(), $ticket);

        $project = $ticket->workItem;
        $timeline = $this->buildTimeline($ticket, $request->user(), 25);

        return response()->json([
            'ticket' => $this->ticketPayload($ticket, $request->user()),
            // The whole work order (internal notes only for admins and the assigned employee).
            'work_order' => app(TicketDetailsService::class)->full($ticket, $request->user()),
            // Linked tickets, resolved only within the same project (the ids were validated on save).
            'related_tickets' => empty($ticket->related_ticket_ids) ? [] : Ticket::query()
                ->with('agency')
                ->where('work_item_id', $ticket->work_item_id)
                ->whereIn('id', $ticket->related_ticket_ids)
                ->orderBy('number')
                ->get()
                ->map(fn (Ticket $related): array => ['id' => $related->id, 'key' => $related->key, 'title' => $related->title, 'status' => $related->status])
                ->values(),
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'status' => $project->status,
                'agency_name' => $project->agency?->name,
                'employee' => $project->employee ? ['id' => $project->employee->id, 'name' => $project->employee->name] : null,
                'ghl_brief' => app(\App\Services\ProjectBriefService::class)->full($project, $request->user()),
            ],
            'timeline' => $timeline,
        ]);
    }

    public function timeline(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorizeTicketView($request->user(), $ticket);

        $user = $request->user();
        $canViewInternal = app(TicketAccess::class)->canSeeInternal($user, $ticket);

        $paginated = $ticket->events()
            ->when(! $canViewInternal, fn ($query) => $query->where('visibility', '!=', 'internal'))
            ->orderByDesc('id')
            ->cursorPaginate(25);

        $commentIds = collect($paginated->items())->pluck('payload.comment_id')->filter()->all();
        $commentsById = ! empty($commentIds)
            ? TicketComment::whereIn('id', $commentIds)->pluck('body', 'id')
            : collect();

        $attachmentsByEvent = app(AttachmentPresenter::class)->forEvents(collect($paginated->items())->pluck('id'), $canViewInternal, $user, $ticket);
        $items = collect($paginated->items())->map(function (TicketEvent $event) use ($commentsById, $attachmentsByEvent, $user): array {
            $commentBody = isset($event->payload['comment_id']) ? ($commentsById[$event->payload['comment_id']] ?? null) : null;

            return [
                'id' => $event->id,
                'type' => $event->type,
                'actor' => (function () use ($event, $user): array {
                    $a = $this->presentAuthor($user, $event->actor_id, $event->actor_name ?? 'System', $event->actor_role ?? 'system');

                    return ['name' => $a['name'], 'role' => $a['role']];
                })(),
                'visibility' => $event->visibility,
                'created_at' => $event->created_at?->toISOString(),
                'payload' => $event->payload ?? [],
                'comment' => $commentBody,
                'attachments' => $attachmentsByEvent[$event->id] ?? [],
            ];
        })->values();

        return response()->json([
            'events' => $items,
            'next_cursor' => $paginated->nextCursor()?->encode(),
            'prev_cursor' => $paginated->previousCursor()?->encode(),
        ]);
    }

    private function buildTimeline(Ticket $ticket, User $user, int $limit = 25): array
    {
        $canViewInternal = app(TicketAccess::class)->canSeeInternal($user, $ticket);

        $events = $ticket->events()
            ->when(! $canViewInternal, fn ($query) => $query->where('visibility', '!=', 'internal'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $commentIds = $events->pluck('payload.comment_id')->filter()->all();
        $commentsById = ! empty($commentIds)
            ? TicketComment::whereIn('id', $commentIds)->pluck('body', 'id')
            : collect();

        $attachmentsByEvent = app(AttachmentPresenter::class)->forEvents($events->pluck('id'), $canViewInternal, $user, $ticket);

        return $events->map(function (TicketEvent $event) use ($commentsById, $attachmentsByEvent, $user): array {
            $commentBody = isset($event->payload['comment_id']) ? ($commentsById[$event->payload['comment_id']] ?? null) : null;

            return [
                'id' => $event->id,
                'type' => $event->type,
                'actor' => (function () use ($event, $user): array {
                    $a = $this->presentAuthor($user, $event->actor_id, $event->actor_name ?? 'System', $event->actor_role ?? 'system');

                    return ['name' => $a['name'], 'role' => $a['role']];
                })(),
                'visibility' => $event->visibility,
                'created_at' => $event->created_at?->toISOString(),
                'payload' => $event->payload ?? [],
                'comment' => $commentBody,
                'attachments' => $attachmentsByEvent[$event->id] ?? [],
            ];
        })->values()->all();
    }

    public function authorizeTicketView(User $user, Ticket $ticket): void
    {
        app(TicketAccess::class)->authorizeView($user, $ticket);
    }

    /**
     * One rule for reading and writing comments: admins, the ticket's assigned employee, and
     * agency owners/clients of the ticket's (active) agency.
     */
    private function authorizeComments(User $user, Ticket $ticket): void
    {
        if ($ticket->usesLifecycle()) {
            abort_unless(app(TicketAccess::class)->canView($user, $ticket), 403);
            // A support ticket's last word belongs to the support module: it also marks first response, resumes waiting tickets and flags secrets.
            return;
        }
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
    /**
     * Agency users never see who on the platform side wrote something: admin and other internal staff are shown as
     * "{agency display name} Support" with no id and no role.
     *
     * @return array{id: int|null, name: string, role: string}
     */
    private function presentAuthor(User $viewer, ?int $id, string $name, string $role): array
    {
        if ($viewer->role->isAgencyRole() && in_array($role, ['admin', 'team_lead', 'qa'], true) && $viewer->agency) {
            return ['id' => null, 'name' => \App\Services\AgencyBrandingPresenter::displayName($viewer->agency).' Support', 'role' => 'support'];
        }

        return ['id' => $id, 'name' => $name, 'role' => $role];
    }

    /**
     * @return array<string, mixed>
     */
    private function commentPayload(TicketComment $comment, User $viewer): array
    {
        return [
            'id' => $comment->id,
            'ticket_id' => $comment->ticket_id,
            'user' => $this->presentAuthor($viewer, $comment->user->id, $comment->user->name, $comment->user->role->value),
            'body' => $comment->body,
            'is_internal' => (bool) $comment->is_internal,
            'created_at' => $comment->created_at?->toISOString(),
            'updated_at' => $comment->updated_at?->toISOString(),
        ];
    }


    /**
     * A completed project's own `work_item_allocations` all sit inside its original (now-past) work
     * window, consumed by whatever tickets were done when it completed. Re-running the planner against
     * that pool as-is would place a brand-new follow-up ticket on those same past dates, making it look
     * overdue the instant it is created. Instead: book fresh capacity for the assigned employee starting
     * today, add it to the project's hours and allocation pool, and put the project back `in_progress`
     * so the usual ticket workflow (and project date/hours editing) opens up again.
     */
    private function reopenCompletedProjectForFollowUp(WorkItem $lockedWorkItem, float $newTicketHours, User $actor): void
    {
        $newTotalHours = round((float) $lockedWorkItem->estimated_hours + $newTicketHours, 2);
        $maxHours = (float) config('scheduling.max_project_hours', 180);
        if ($newTotalHours > $maxHours) {
            throw ValidationException::withMessages([
                'estimated_hours' => "Adding this ticket would take the project to {$newTotalHours} h, over the {$maxHours} h limit. Reduce the estimate or edit the project's hours first.",
            ]);
        }

        $employee = User::query()->lockForUpdate()->findOrFail($lockedWorkItem->employee_id);

        // The project's own `work_item_allocations` may still hold more than its done tickets actually
        // used (an owner rarely estimates to the exact hour), and that leftover sits on dates from the
        // original, now-past work window. TicketPlanner assigns a project's pool earliest-date-first, so
        // that leftover would otherwise be handed straight to the new ticket, dating it in the past.
        // Reconcile the pool down to exactly what is still owed (the done tickets' real hours, on their
        // real dates) before adding anything new: no future ticket may ever be backdated into stale slack.
        $consumedByDate = DB::table('ticket_allocations')
            ->join('tickets', 'tickets.id', '=', 'ticket_allocations.ticket_id')
            ->where('tickets.work_item_id', $lockedWorkItem->id)
            ->where('tickets.status', 'done')
            ->selectRaw('ticket_allocations.work_date, SUM(ticket_allocations.hours) AS hours')
            ->groupBy('ticket_allocations.work_date')
            ->get();

        DB::table('work_item_allocations')->where('work_item_id', $lockedWorkItem->id)->delete();

        foreach ($consumedByDate as $row) {
            DB::table('work_item_allocations')->insert([
                'work_item_id' => $lockedWorkItem->id,
                'employee_id' => $employee->id,
                'work_date' => $row->work_date,
                'hours' => $row->hours,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $scheduler = app(WorkItemScheduler::class);
        $timezone = config('app.timezone');
        $start = CarbonImmutable::now($timezone);
        $searchLimit = $start->addDays(90);
        $requestedUnits = (int) round($newTicketHours * 100);

        $plan = $scheduler->plan($employee, $start, $searchLimit, $requestedUnits);
        abort_if($plan === null, 422, 'Could not find enough availability for this employee in the next 90 days. Try a smaller estimate.');

        foreach ($plan as $slot) {
            DB::table('work_item_allocations')->insert([
                'work_item_id' => $lockedWorkItem->id,
                'employee_id' => $employee->id,
                'work_date' => $slot['work_date'],
                'hours' => WorkItemScheduler::unitsToHours($slot['units']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $newDue = CarbonImmutable::parse(end($plan)['work_date'], $timezone);
        $projectBefore = $lockedWorkItem->status;
        $lockedWorkItem->forceFill([
            'status' => 'in_progress',
            'estimated_hours' => $newTotalHours,
            'due_date' => $newDue->greaterThan($lockedWorkItem->due_date) ? $newDue->toDateString() : $lockedWorkItem->due_date->toDateString(),
        ])->save();

        app(ActivityLogger::class)->projectStatusChanged($actor, $lockedWorkItem, $projectBefore, 'in_progress');
        $projectForNotice = $lockedWorkItem->fresh()->load('client');
        DB::afterCommit(function () use ($projectForNotice, $projectBefore, $actor): void {
            app(WorkflowNotificationService::class)->projectStatusChanged($projectForNotice, $projectBefore, 'in_progress', $actor);
        });
    }


    /**
     * Constraint for counting/listing comments a viewer may see: agency roles never see internal notes.
     */
    private function commentVisibility(?User $viewer): \Closure
    {
        $isAgency = $viewer !== null && in_array($viewer->role, [Role::AgencyOwner, Role::AgencyClient], true);

        return fn ($query) => $isAgency ? $query->where('is_internal', false) : $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketPayload(Ticket $ticket, ?User $viewer = null): array
    {
        $ticket->loadMissing(['agency', 'allocations', 'creator', 'workItem.assignment', 'latestStatusEvent', 'pendingHoursRequest']);

        $loggedMinutes = array_key_exists('logged_minutes', $ticket->getAttributes())
            ? (int) $ticket->logged_minutes
            : (int) $ticket->timeEntries()->whereNotNull('minutes')->sum('minutes');
        $workflow = app(TicketWorkflow::class);
        $allowedActions = $viewer ? $workflow->allowedActions($ticket, $viewer) : [];

        $waitingOn = match ($ticket->status) {
            'in_review', 'waiting_on_client' => 'client',
            'todo', 'in_progress', 'changes_requested' => 'employee',
            'blocked' => 'team',
            default => null,
        };

        $latestStatusEvent = $ticket->latestStatusEvent;

        $waitingSince = $latestStatusEvent?->created_at?->toISOString()
            ?? $ticket->created_at?->toISOString();

        // The latest status change is the one that moved the ticket into its current state, so its
        // linked comment is the reason for blocked / waiting_on_client / changes_requested.
        $statusReason = null;
        if (in_array($ticket->status, ['blocked', 'waiting_on_client', 'changes_requested'], true)) {
            $commentId = $latestStatusEvent?->payload['comment_id'] ?? null;
            if ($commentId) {
                $statusReason = TicketComment::find($commentId)?->body;
            }
        }

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
            'service_type' => $ticket->service_type,
            'details' => $ticket->details,
            'details_summary' => app(TicketDetailsService::class)->summary($ticket),
            'brief' => [
                'steps_to_reproduce' => $ticket->steps_to_reproduce,
                'expected_result' => $ticket->expected_result,
                'actual_result' => $ticket->actual_result,
                'affected_url' => $ticket->affected_url,
                'environment' => $ticket->environment,
                'severity' => $ticket->severity,
                'due_preference' => $ticket->due_preference,
                'related_ticket_ids' => $ticket->related_ticket_ids ?? [],
            ],
            'status' => $ticket->status,
            'timeline_status' => $ticket->timeline_status,
            'position' => $ticket->position,
            'start_date' => $ticket->start_date?->toDateString(),
            'due_date' => $ticket->due_date?->toDateString(),
            'allowed_actions' => $allowedActions,
            'updated_at' => $ticket->updated_at?->toISOString(),
            // An open request for more hours (the employee's ask, the agency's decision): null when there is none.
            'hours_request' => (function () use ($ticket): ?array {
                $open = $ticket->loadMissing('pendingHoursRequest')->pendingHoursRequest;

                return $open ? ['id' => $open->id, 'hours' => $open->hours, 'reason' => $open->reason, 'created_at' => $open->created_at?->toISOString()] : null;
            })(),
            'waiting_on' => $waitingOn,
            'waiting_since' => $waitingSince,
            'status_reason' => $statusReason,
            'logged_hours' => TimeTracker::hours($loggedMinutes),
            'over_estimate' => (float) $ticket->estimated_hours > 0 && TimeTracker::hours($loggedMinutes) > (float) $ticket->estimated_hours,
            // True only for the assigned employee while their timer runs on this ticket.
            'timer_running' => $viewer !== null
                && app(TicketAccess::class)->isAssignedEmployee($viewer, $ticket)
                && app(TimeTracker::class)->runningFor($viewer)?->ticket_id === $ticket->id,
            'comments_count' => (int) (array_key_exists('comments_count', $ticket->getAttributes())
                ? $ticket->comments_count
                : $ticket->comments()->where($this->commentVisibility($viewer))->count()),
            'schedule' => $ticket->allocations
                ->sortBy('work_date')
                ->map(fn ($allocation): array => [
                    'work_date' => $allocation->work_date->toDateString(),
                    'hours' => $allocation->hours,
                ])->values(),
        ];
    }
}
