<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ListAdminTicketsRequest;
use App\Http\Requests\ReorderTicketsRequest;
use App\Http\Requests\StoreTicketCommentRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Requests\UpdateTicketStatusRequest;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\TicketPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketController extends Controller
{
    public function index(Request $request, WorkItem $workItem): JsonResponse
    {
        $this->authorizeProject($request->user(), $workItem);

        return response()->json([
            'tickets' => $workItem->tickets()
                ->with(['allocations', 'creator'])
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (Ticket $ticket): array => $this->ticketPayload($ticket))
                ->values(),
        ]);
    }

    public function store(StoreTicketRequest $request, WorkItem $workItem, TicketPlanner $planner): JsonResponse
    {
        $user = $request->user();
        $this->authorizeProject($user, $workItem, requireOpen: true);
        $data = $request->validated();

        $ticket = DB::transaction(function () use ($workItem, $user, $data, $planner): Ticket {
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($lockedWorkItem);
            $position = ((int) $lockedWorkItem->tickets()->max('position')) + 1;

            $ticket = $lockedWorkItem->tickets()->create([
                ...$data,
                'created_by' => $user->id,
                'position' => $position,
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'todo',
            ]);

            $planner->plan($lockedWorkItem);

            return $ticket;
        });

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh()->load(['allocations', 'creator'])),
        ], 201);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, TicketPlanner $planner): JsonResponse
    {
        $user = $request->user();
        $workItem = $ticket->workItem;
        $this->authorizeProject($user, $workItem, requireOpen: true);
        $data = $request->validated();

        DB::transaction(function () use ($ticket, $workItem, $data, $planner): void {
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($lockedWorkItem);
            $lockedTicket = $lockedWorkItem->tickets()->lockForUpdate()->findOrFail($ticket->id);
            $lockedTicket->update($data);
            $planner->plan($lockedWorkItem);
        });

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh()->load(['allocations', 'creator'])),
        ]);
    }

    public function cancel(Request $request, Ticket $ticket, TicketPlanner $planner): JsonResponse
    {
        $workItem = $ticket->workItem;
        $this->authorizeProject($request->user(), $workItem, requireOpen: true);

        abort_unless(! in_array($ticket->status, ['done', 'cancelled'], true), 422, 'This ticket is already closed.');

        DB::transaction(function () use ($ticket, $workItem, $planner): void {
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($lockedWorkItem);
            $lockedTicket = $lockedWorkItem->tickets()->lockForUpdate()->findOrFail($ticket->id);
            abort_unless(! in_array($lockedTicket->status, ['done', 'cancelled'], true), 422, 'This ticket is already closed.');
            $lockedTicket->update(['status' => 'cancelled']);
            $planner->plan($lockedWorkItem);
        });

        return response()->json([
            'ticket' => $this->ticketPayload($ticket->fresh()->load(['allocations', 'creator'])),
        ]);
    }

    public function reorder(ReorderTicketsRequest $request, WorkItem $workItem, TicketPlanner $planner): JsonResponse
    {
        $this->authorizeProject($request->user(), $workItem, requireOpen: true);
        $ids = $request->validated('ticket_ids');

        DB::transaction(function () use ($workItem, $ids, $planner): void {
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
                $tickets->firstWhere('id', $ticketId)->update(['position' => $position]);
            }

            $planner->plan($lockedWorkItem);
        });

        return $this->index($request, $workItem->fresh());
    }

    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user();
        $this->ensureActiveEmployeeAgency($employee);
        $tickets = Ticket::query()
            ->with(['allocations', 'creator', 'workItem'])
            ->whereHas('workItem', fn ($query) => $query->where('employee_id', $employee->id))
            ->orderBy('work_item_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Ticket $ticket): array => $this->ticketPayload($ticket));

        return response()->json(['tickets' => $tickets]);
    }

    public function updateStatus(UpdateTicketStatusRequest $request, Ticket $ticket, TicketPlanner $planner): JsonResponse
    {
        $employee = $request->user();
        $this->ensureActiveEmployeeAgency($employee);
        $workItem = $ticket->workItem;
        abort_unless($workItem->employee_id === $employee->id, 404);
        $newStatus = $request->validated('status');
        $transitions = [
            'todo' => 'in_progress',
            'in_progress' => 'in_review',
            'in_review' => 'done',
        ];
        abort_unless(($transitions[$ticket->status] ?? null) === $newStatus, 422, 'This ticket status transition is not allowed.');

        DB::transaction(function () use ($ticket, $workItem, $newStatus, $planner): void {
            $lockedWorkItem = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $lockedTicket = $lockedWorkItem->tickets()->lockForUpdate()->findOrFail($ticket->id);
            $transitions = [
                'todo' => 'in_progress',
                'in_progress' => 'in_review',
                'in_review' => 'done',
            ];
            abort_unless(($transitions[$lockedTicket->status] ?? null) === $newStatus, 422, 'This ticket status transition is not allowed.');
            $lockedTicket->update(['status' => $newStatus]);

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

            $planner->plan($lockedWorkItem);
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

    public function comment(StoreTicketCommentRequest $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        $workItem = $ticket->workItem;
        $isAdmin = $user->role === Role::Admin;
        $isAssignedEmployee = $user->role === Role::Employee && $workItem->employee_id === $user->id;
        $isAgencyParticipant = in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true)
            && $this->canAccessProject($user, $workItem);
        abort_unless($isAdmin || $isAssignedEmployee || $isAgencyParticipant, 403);

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $request->validated('body'),
        ]);

        return response()->json([
            'comment' => [
                'id' => $comment->id,
                'ticket_id' => $comment->ticket_id,
                'user' => ['id' => $user->id, 'name' => $user->name],
                'body' => $comment->body,
            ],
        ], 201);
    }

    private function authorizeProject(User $user, WorkItem $workItem, bool $requireOpen = false): void
    {
        abort_unless($this->canAccessProject($user, $workItem), 404);

        if ($requireOpen) {
            $this->ensureOpenProject($workItem);
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

        return $user->role === Role::AgencyOwner || $workItem->client_id === $user->id;
    }

    private function ensureOpenProject(WorkItem $workItem): void
    {
        abort_unless(in_array($workItem->status, ['scheduled', 'in_progress'], true), 422, 'Tickets can only be changed on open projects.');
    }

    private function ensureActiveEmployeeAgency(User $user): void
    {
        if ($user->agency_id !== null) {
            abort_unless($user->agency?->status === 'active', 403, 'An active agency is required to access employee tickets.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketPayload(Ticket $ticket): array
    {
        $ticket->loadMissing(['allocations', 'creator']);

        return [
            'id' => $ticket->id,
            'work_item_id' => $ticket->work_item_id,
            'created_by' => $ticket->created_by,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'estimated_hours' => $ticket->estimated_hours,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'position' => $ticket->position,
            'start_date' => $ticket->start_date?->toDateString(),
            'due_date' => $ticket->due_date?->toDateString(),
            'schedule' => $ticket->allocations
                ->sortBy('work_date')
                ->map(fn ($allocation): array => [
                    'work_date' => $allocation->work_date->toDateString(),
                    'hours' => $allocation->hours,
                ])->values(),
        ];
    }
}
