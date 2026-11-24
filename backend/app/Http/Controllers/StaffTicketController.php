<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\StaffCapability;
use App\Services\Support\SupportTicketService;
use App\Services\Support\SupportWorkflow;
use App\Services\Support\TicketPresenter;
use App\Services\Support\TicketSearch;
use App\Services\TicketAccess;
use App\Support\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The staff console API. Capabilities decide who sees the whole queue; an employee with none sees only their own tickets. */
class StaffTicketController extends Controller
{
    public const VIEWS = ['my', 'new', 'unassigned', 'critical', 'waiting_agency', 'waiting_third_party', 'qa', 'resolved', 'reopened', 'all'];

    public function __construct(private TicketSearch $search) {}

    private function scope(User $user): Builder
    {
        $query = Ticket::query()->whereNotNull('ref');
        if (StaffCapability::any($user)) {
            return $query;
        }

        // An employee without a capability keeps seeing exactly their own work.
        return $query->where(fn (Builder $q) => $q->where('assigned_to_id', $user->id)->orWhereHas('workItem', fn (Builder $w) => $w->where('employee_id', $user->id)));
    }

    private function view(Builder $query, string $view, User $user): Builder
    {
        $open = fn (Builder $b) => $b->whereNotIn('status', [...TicketStatus::stored('resolved'), ...TicketStatus::stored('closed'), ...TicketStatus::stored('rejected')]);

        return match ($view) {
            'my' => $open($query->where('assigned_to_id', $user->id)),
            'new' => $query->where('status', 'new'),
            'unassigned' => $query->whereNull('assigned_to_id')->whereIn('status', ['new', 'triage']),
            'critical' => $open($query->where('priority', 'urgent')),
            'waiting_agency' => $query->whereIn('status', TicketStatus::stored('waiting_agency')),
            'waiting_third_party' => $query->whereIn('status', TicketStatus::stored('waiting_third_party')),
            'qa' => $query->whereIn('status', TicketStatus::stored('qa')),
            'resolved' => $query->whereIn('status', TicketStatus::stored('resolved')),
            'reopened' => $open($query->where('reopened_count', '>', 0)),
            default => $query,
        };
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $f = $request->validate([
            'view' => ['sometimes', Rule::in(self::VIEWS)], 'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'agency_id' => ['sometimes', 'nullable', 'integer'], 'end_client_id' => ['sometimes', 'nullable', 'integer'], 'project_id' => ['sometimes', 'nullable', 'integer'],
            'type' => ['sometimes', 'nullable', 'string', 'max:40'], 'kind' => ['sometimes', 'nullable', Rule::in(['support', 'fulfillment', 'change_request'])],
            'priority' => ['sometimes', 'nullable', Rule::in(['low', 'normal', 'high', 'urgent'])], 'status' => ['sometimes', 'nullable', Rule::in(TicketStatus::LIFECYCLE)],
            'service' => ['sometimes', 'nullable', 'string', 'max:30'], 'assigned_to_id' => ['sometimes', 'nullable', 'integer'], 'team' => ['sometimes', 'nullable', 'string', 'max:40'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in(['updated', 'created', 'priority'])],
        ]);
        $base = $this->scope($user);
        $counts = [];
        foreach (self::VIEWS as $name) {
            $counts[$name] = $this->view(clone $base, $name, $user)->count();
        }
        $query = $this->search->apply($this->view(clone $base, $f['view'] ?? 'all', $user), $f, true)->with(['agency', 'endClient', 'relatedProject', 'assignee']);
        $query = match ($f['sort'] ?? 'updated') {
            'created' => $query->orderByDesc('created_at'),
            'priority' => $query->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")->orderByDesc('updated_at'),
            default => $query->orderByDesc('updated_at'),
        };
        $page = $query->orderByDesc('id')->paginate($f['per_page'] ?? 25);

        return response()->json([
            'tickets' => collect($page->items())->map(fn (Ticket $t): array => TicketPresenter::staffSummary($t))->values(),
            'counts' => $counts,
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $ref): JsonResponse
    {
        $ticket = $this->find($request, $ref);

        return response()->json(['ticket' => TicketPresenter::staff($ticket, $request->user())]);
    }

    public function transition(Request $request, string $ref, SupportWorkflow $workflow): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in(TicketStatus::LIFECYCLE)],
            'assigned_to_id' => ['nullable', 'integer'], 'assigned_team' => ['nullable', 'string', 'max:40'], 'reviewer_id' => ['nullable', 'integer'],
            'waiting_reason' => ['nullable', 'string', 'max:500'], 'follow_up_date' => ['nullable', 'date_format:Y-m-d'],
            'resolution_summary' => ['nullable', 'string', 'max:5000'], 'resolution_category' => ['nullable', Rule::in(array_keys(config('support.resolution_categories')))],
            'root_cause' => ['nullable', 'string', 'max:5000'], 'reason' => ['nullable', 'string', 'max:2000'], 'failure_note' => ['nullable', 'string', 'max:5000'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $ticket = $this->find($request, $ref);
        $ticket = $workflow->transition($ticket, $data['to'], $request->user(), $data);

        return response()->json(['ticket' => TicketPresenter::staff($this->load($ticket), $request->user())]);
    }

    public function assign(Request $request, string $ref, SupportTicketService $service): JsonResponse
    {
        $data = $request->validate(['assigned_to_id' => ['sometimes', 'nullable', 'integer'], 'reviewer_id' => ['sometimes', 'nullable', 'integer'], 'assigned_team' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $ticket = $service->assign($this->find($request, $ref), $request->user(), $data);

        return response()->json(['ticket' => TicketPresenter::staff($this->load($ticket), $request->user())]);
    }

    /** Priority, kind and service: the fields triage sets. Each change is audited. */
    public function update(Request $request, string $ref, SupportTicketService $service): JsonResponse
    {
        $data = $request->validate([
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])], 'priority_reason' => ['nullable', 'string', 'max:1000'],
            'kind' => ['sometimes', Rule::in(['support', 'fulfillment', 'change_request'])], 'service' => ['sometimes', 'nullable', Rule::in(array_keys(config('support.services')))],
        ]);
        $ticket = $this->find($request, $ref);
        if (isset($data['priority'])) {
            $service->changePriority($ticket, $request->user(), $data['priority'], $data['priority_reason'] ?? null);
        }
        if (array_key_exists('kind', $data) || array_key_exists('service', $data)) {
            abort_unless(StaffCapability::manages($request->user()), 403);
            $ticket->forceFill(array_intersect_key($data, ['kind' => 1, 'service' => 1]))->save();
        }

        return response()->json(['ticket' => TicketPresenter::staff($this->load($ticket->fresh()), $request->user())]);
    }

    /** Staff for the assignee and reviewer pickers. */
    public function people(Request $request): JsonResponse
    {
        abort_unless(StaffCapability::any($request->user()), 403);
        $people = User::query()->whereIn('role', [Role::Admin, Role::Employee])->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role', 'staff_capabilities']);

        return response()->json(['people' => $people->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'capabilities' => $u->role === Role::Admin ? StaffCapability::ALL : ($u->staff_capabilities ?? [])])->values()]);
    }

    /** Admin only: which capabilities a person holds. */
    public function setCapabilities(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->role === Role::Admin, 403);
        abort_unless(in_array($user->role, [Role::Employee], true), 422, 'Capabilities apply to employees.');
        $data = $request->validate(['capabilities' => ['present', 'array'], 'capabilities.*' => [Rule::in(StaffCapability::ALL)]]);
        $user->forceFill(['staff_capabilities' => array_values(array_unique($data['capabilities']))])->save();

        return response()->json(['person' => ['id' => $user->id, 'name' => $user->name, 'capabilities' => $user->staff_capabilities]]);
    }

    private function find(Request $request, string $ref): Ticket
    {
        $ticket = Ticket::query()->where('ref', $ref)->with(['agency', 'endClient', 'relatedProject', 'assignee', 'reviewer', 'submitter', 'workItem'])->firstOrFail();
        abort_unless(StaffCapability::isStaff($request->user()) && app(TicketAccess::class)->canView($request->user(), $ticket), 404);

        return $ticket;
    }

    private function load(Ticket $ticket): Ticket
    {
        return $ticket->load(['agency', 'endClient', 'relatedProject', 'assignee', 'reviewer', 'submitter']);
    }
}
