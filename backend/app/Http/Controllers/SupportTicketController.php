<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreSupportTicketRequest;
use App\Models\EndClient;
use App\Models\Ticket;
use App\Models\WorkItem;
use App\Services\Support\SecretScanner;
use App\Services\Support\SupportTicketService;
use App\Services\Support\TicketOperations;
use App\Services\Support\SupportWorkflow;
use App\Services\Support\TicketPresenter;
use App\Services\Support\TicketSearch;
use App\Support\TicketStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The agency portal side of the support module. Every query starts from the caller's own agency. */
class SupportTicketController extends Controller
{
    public function __construct(private TicketSearch $search) {}

    /** The submit form: types and their questions, services, priorities and the agency's own end clients and projects. */
    public function form(Request $request): JsonResponse
    {
        $agencyId = $request->user()->agency_id;
        $types = [];
        foreach (config('support.types') as $key => $type) {
            $types[] = [
                'key' => $key, 'label' => $type['label'], 'example' => $type['example'],
                'fields' => collect(config("support.type_fields.{$key}", []))->map(fn (array $f, string $name): array => ['key' => $name, 'label' => $f[0], 'required' => $f[1], 'input' => $f[2]])->values(),
            ];
        }

        return response()->json([
            'types' => $types,
            'services' => collect(config('support.services'))->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values(),
            'priorities' => collect(config('support.priorities'))->map(fn (array $p, string $key): array => ['key' => $key] + $p)->values(),
            'end_clients' => EndClient::query()->where('agency_id', $agencyId)->orderBy('name')->get(['id', 'name']),
            'projects' => WorkItem::query()->where('agency_id', $agencyId)->whereIn('status', ['scheduled', 'in_progress'])->orderBy('title')->get(['id', 'title', 'client_id']),
            'secret_warning' => SecretScanner::MESSAGE,
        ]);
    }

    public function suggestPriority(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(config('support.types')))],
            'impact' => ['nullable', 'array'], 'impact.live_broken' => ['nullable', 'boolean'], 'impact.revenue_affected' => ['nullable', 'boolean'], 'impact.workaround' => ['nullable', 'boolean'],
        ]);
        $priority = SupportTicketService::suggestPriority($data['type'], $data['impact'] ?? []);

        return response()->json(['priority' => $priority, 'label' => config("support.priorities.{$priority}.label")]);
    }

    /** Text typed so far is scanned as the person writes, so the warning appears before they submit. */
    public function scanText(Request $request, SecretScanner $scanner): JsonResponse
    {
        $data = $request->validate(['text' => ['nullable', 'string', 'max:20000']]);

        return response()->json(['found' => $scanner->scan($data['text'] ?? ''), 'message' => SecretScanner::MESSAGE]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $f = $request->validate([
            'tab' => ['sometimes', Rule::in(['open', 'waiting', 'resolved', 'all'])], 'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'end_client_id' => ['sometimes', 'nullable', 'integer'], 'project_id' => ['sometimes', 'nullable', 'integer'], 'type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'priority' => ['sometimes', 'nullable', Rule::in(['low', 'normal', 'high', 'urgent'])], 'status' => ['sometimes', 'nullable', Rule::in(TicketStatus::LIFECYCLE)],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $scope = Ticket::query()->where('agency_id', $user->agency_id)->whereNotNull('ref');
        $counts = $this->search->tabCounts($this->search->statusCounts($scope));
        $page = $this->search->apply(clone $scope, $f, false)
            ->with(['agency', 'endClient', 'relatedProject', 'assignee'])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate($f['per_page'] ?? 25);

        return response()->json([
            'tickets' => collect($page->items())->map(fn (Ticket $t): array => TicketPresenter::agencySummary($t))->values(),
            'counts' => $counts,
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(StoreSupportTicketRequest $request, SupportTicketService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [Role::AgencyOwner, Role::AgencyClient], true) && $user->agency_id !== null, 403);
        $ticket = $service->create($user, $request->validated())->load(['agency', 'endClient', 'relatedProject']);

        return response()->json([
            'ticket' => TicketPresenter::agency($ticket, $user),
            'next' => ['received', 'triage', 'assigned'],
            'warnings' => $ticket->secret_flagged_at ? ['secret' => SecretScanner::MESSAGE] : [],
        ], 201);
    }

    public function show(Request $request, string $ref): JsonResponse
    {
        $ticket = $this->find($request, $ref);

        return response()->json(['ticket' => TicketPresenter::agency($ticket, $request->user())]);
    }

    public function reopen(Request $request, string $ref, SupportWorkflow $workflow): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $ticket = $this->find($request, $ref);
        $ticket = $workflow->transition($ticket, 'triage', $request->user(), $data);

        return response()->json(['ticket' => TicketPresenter::agency($this->load($ticket), $request->user())]);
    }

    /** "Confirm resolved": the agency accepts the resolution and the ticket closes. */
    public function confirm(Request $request, string $ref, SupportWorkflow $workflow): JsonResponse
    {
        $ticket = $this->find($request, $ref);
        $ticket = $workflow->transition($ticket, 'closed', $request->user());

        return response()->json(['ticket' => TicketPresenter::agency($this->load($ticket), $request->user())]);
    }

    /** One satisfaction answer, 1 to 5, once the ticket is resolved or closed. */
    public function feedback(Request $request, string $ref, TicketOperations $ops): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $ops->feedback($this->find($request, $ref), $request->user(), $data['rating'], $data['comment'] ?? null);

        return response()->json(['message' => 'Thank you for your feedback.']);
    }

    /** The account owner accepts or declines the quote on this ticket. */
    public function answerQuote(Request $request, string $ref, \App\Services\Support\TicketQuotes $quotes): JsonResponse
    {
        $data = $request->validate(['accept' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        $ticket = $this->find($request, $ref);
        $quotes->decide($ticket, $request->user(), (bool) $data['accept'], $data['note'] ?? null);

        return response()->json(['ticket' => TicketPresenter::agency($this->load($ticket->fresh()), $request->user())]);
    }

    public function endClients(Request $request): JsonResponse
    {
        return response()->json(['end_clients' => EndClient::query()->where('agency_id', $request->user()->agency_id)->orderBy('name')->get(['id', 'name', 'contact_name', 'contact_email'])]);
    }

    public function storeEndClient(Request $request): JsonResponse
    {
        $agencyId = $request->user()->agency_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('end_clients', 'name')->where('agency_id', $agencyId)],
            'contact_name' => ['nullable', 'string', 'max:120'], 'contact_email' => ['nullable', 'email', 'max:254'], 'external_ref' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json(['end_client' => EndClient::create($data + ['agency_id' => $agencyId])->only(['id', 'name', 'contact_name', 'contact_email'])], 201);
    }

    /** The ticket by ref inside the caller's agency; anything else is a 404 so existence is not disclosed. */
    private function find(Request $request, string $ref): Ticket
    {
        $user = $request->user();

        return Ticket::query()->where('ref', $ref)->where('agency_id', $user->agency_id)
            ->with(['agency', 'endClient', 'relatedProject', 'assignee'])->firstOrFail();
    }

    private function load(Ticket $ticket): Ticket
    {
        return $ticket->load(['agency', 'endClient', 'relatedProject', 'assignee']);
    }
}
