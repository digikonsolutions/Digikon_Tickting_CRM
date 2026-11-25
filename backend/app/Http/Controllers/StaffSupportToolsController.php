<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\Ticket;
use App\Services\Support\StaffCapability;
use App\Services\Support\SupportSettings;
use App\Services\Support\TicketApprovals;
use App\Services\Support\TicketQa;
use App\Services\TicketAccess;
use App\Services\TicketEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The staff side panels of a ticket (QA, bug, approvals, relations, watchers) and the admin settings. */
class StaffSupportToolsController extends Controller
{
    private function ticket(Request $request, string $ref): Ticket
    {
        $ticket = Ticket::query()->where('ref', $ref)->firstOrFail();
        abort_unless(StaffCapability::isStaff($request->user()) && app(TicketAccess::class)->canView($request->user(), $ticket), 404);

        return $ticket;
    }

    // QA and bug details

    public function qa(Request $request, string $ref, TicketQa $qa): JsonResponse
    {
        $ticket = $this->ticket($request, $ref);

        return response()->json(['library' => $qa->library($ticket), 'runs' => $qa->history($ticket), 'bug' => $qa->bug($ticket)]);
    }

    public function recordQa(Request $request, string $ref, TicketQa $qa): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:80'], 'items.*.list' => ['required', 'string', 'max:30'], 'items.*.label' => ['required', 'string', 'max:200'],
            'items.*.result' => ['required', Rule::in(['pass', 'fail', 'na'])], 'items.*.note' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:5000'], 'evidence_url' => ['nullable', 'url:http,https', 'max:1000'],
            'resolution_summary' => ['nullable', 'string', 'max:5000'], 'resolution_category' => ['nullable', Rule::in(array_keys(config('support.resolution_categories')))],
        ]);
        $ticket = $qa->run($this->ticket($request, $ref), $request->user(), $data);

        return response()->json(['status' => $ticket->status, 'runs' => $qa->history($ticket)]);
    }

    public function saveBug(Request $request, string $ref, TicketQa $qa): JsonResponse
    {
        $data = $request->validate(['severity' => ['sometimes', 'nullable', Rule::in(['critical', 'high', 'medium', 'low'])], 'component' => ['sometimes', 'nullable', Rule::in(['workflow', 'crm', 'ai', 'website', 'integration', 'other'])], 'bug_status' => ['sometimes', Rule::in(['open', 'in_progress', 'fixed', 'retest', 'closed'])]]);

        return response()->json(['bug' => $qa->saveBug($this->ticket($request, $ref), $request->user(), $data)]);
    }

    // Approvals

    public function approvals(Request $request, string $ref, TicketApprovals $approvals): JsonResponse
    {
        return response()->json(['approvals' => $approvals->list($this->ticket($request, $ref), $request->user())]);
    }

    public function requestApproval(Request $request, string $ref, TicketApprovals $approvals): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(TicketApprovals::DECIDERS))], 'note' => ['nullable', 'string', 'max:1000']]);
        $ticket = $this->ticket($request, $ref);
        $approvals->request($ticket, $request->user(), $data['kind'], $data['note'] ?? null);

        return response()->json(['approvals' => $approvals->list($ticket, $request->user())], 201);
    }

    public function decideApproval(Request $request, string $ref, int $approval, TicketApprovals $approvals): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        $ticket = $this->ticket($request, $ref);
        $approvals->decide($ticket, $approval, $request->user(), (bool) $data['approve'], $data['note'] ?? null);

        return response()->json(['approvals' => $approvals->list($ticket, $request->user())]);
    }

    // Relations and watchers

    public function relations(Request $request, string $ref): JsonResponse
    {
        $ticket = $this->ticket($request, $ref);
        $rows = DB::table('ticket_relations')->join('tickets', 'tickets.id', '=', 'ticket_relations.related_ticket_id')->where('ticket_relations.ticket_id', $ticket->id)
            ->get(['ticket_relations.id', 'ticket_relations.type', 'tickets.ref', 'tickets.title', 'tickets.status']);
        $watching = DB::table('ticket_watchers')->where('ticket_id', $ticket->id)->where('user_id', $request->user()->id)->exists();

        return response()->json(['relations' => $rows, 'watching' => $watching, 'watchers' => DB::table('ticket_watchers')->where('ticket_id', $ticket->id)->count()]);
    }

    public function addRelation(Request $request, string $ref, TicketEventRecorder $recorder): JsonResponse
    {
        $data = $request->validate(['ref' => ['required', 'string', 'max:30'], 'type' => ['required', Rule::in(['related', 'duplicate'])]]);
        $ticket = $this->ticket($request, $ref);
        $other = Ticket::query()->where('ref', $data['ref'])->first();
        abort_if($other === null || $other->id === $ticket->id, 422, 'Choose another ticket by its ID.');
        abort_unless(StaffCapability::isStaff($request->user()) && app(TicketAccess::class)->canView($request->user(), $other), 404);
        DB::table('ticket_relations')->updateOrInsert(['ticket_id' => $ticket->id, 'related_ticket_id' => $other->id], ['type' => $data['type'], 'created_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);
        $recorder->record($ticket, 'related_added', ['ref' => $other->ref, 'type' => $data['type']], $request->user(), 'internal');

        return $this->relations($request, $ref);
    }

    public function removeRelation(Request $request, string $ref, int $relation): JsonResponse
    {
        DB::table('ticket_relations')->where('id', $relation)->where('ticket_id', $this->ticket($request, $ref)->id)->delete();

        return $this->relations($request, $ref);
    }

    public function watch(Request $request, string $ref): JsonResponse
    {
        $ticket = $this->ticket($request, $ref);
        $request->boolean('on', true)
            ? DB::table('ticket_watchers')->insertOrIgnore(['ticket_id' => $ticket->id, 'user_id' => $request->user()->id])
            : DB::table('ticket_watchers')->where('ticket_id', $ticket->id)->where('user_id', $request->user()->id)->delete();

        return $this->relations($request, $ref);
    }

    // Admin: settings and entitlements

    public function settings(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === Role::Admin, 403);

        return response()->json(['settings' => SupportSettings::current()]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === Role::Admin, 403);
        $minutes = ['required', 'integer', 'min:1', 'max:100000'];
        $data = $request->validate([
            'sla' => ['sometimes', 'array'], 'sla.*.response' => $minutes, 'sla.*.resolution' => $minutes,
            'hours' => ['sometimes', 'array'], 'hours.days' => ['required_with:hours', 'array', 'min:1'], 'hours.days.*' => ['integer', 'between:1,7'],
            'hours.start' => ['required_with:hours', 'date_format:H:i'], 'hours.end' => ['required_with:hours', 'date_format:H:i', 'after:hours.start'],
            'timezone' => ['sometimes', 'timezone:all'], 'holidays' => ['sometimes', 'array', 'max:100'], 'holidays.*' => ['date_format:Y-m-d'],
            'at_risk_ratio' => ['sometimes', 'numeric', 'between:0.1,0.99'], 'pause_while_waiting_for_agency' => ['sometimes', 'boolean'],
            'unassigned_escalation_minutes' => ['sometimes', 'array'], 'unassigned_escalation_minutes.*' => ['integer', 'min:1', 'max:10000'],
            'reopen_days' => ['sometimes', 'integer', 'min:1', 'max:365'], 'auto_close_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'waiting_agency_reminder_business_days' => ['sometimes', 'integer', 'min:1', 'max:60'], 'waiting_agency_stalled_business_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);
        // A priority missing from the request keeps its current numbers.
        if (isset($data['sla'])) {
            $data['sla'] = array_replace_recursive(config('support.sla'), $data['sla']);
        }
        if (isset($data['unassigned_escalation_minutes'])) {
            $data['unassigned_escalation_minutes'] = array_replace(config('support.unassigned_escalation_minutes'), $data['unassigned_escalation_minutes']);
        }
        SupportSettings::save($data);

        return response()->json(['settings' => SupportSettings::current()]);
    }

    public function entitlement(Request $request, Agency $agency): JsonResponse
    {
        abort_unless(StaffCapability::has($request->user(), 'finance'), 403);

        return response()->json(['entitlement' => DB::table('agency_entitlements')->where('agency_id', $agency->id)->first(['included_revisions_per_month', 'support_minutes_per_month'])]);
    }

    public function saveEntitlement(Request $request, Agency $agency): JsonResponse
    {
        abort_unless(StaffCapability::has($request->user(), 'finance'), 403);
        $data = $request->validate(['included_revisions_per_month' => ['nullable', 'integer', 'min:0', 'max:1000'], 'support_minutes_per_month' => ['nullable', 'integer', 'min:0', 'max:1000000']]);
        DB::table('agency_entitlements')->updateOrInsert(['agency_id' => $agency->id], $data + ['updated_at' => now(), 'created_at' => now()]);

        return $this->entitlement($request, $agency);
    }

    // Saved views, bulk actions and the workload view

    public function savedViews(Request $request): JsonResponse
    {
        abort_unless(StaffCapability::isStaff($request->user()), 403);

        return response()->json(['views' => DB::table('saved_views')->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name', 'filters'])->map(fn ($v): array => ['id' => $v->id, 'name' => $v->name, 'filters' => json_decode($v->filters, true)])]);
    }

    public function saveView(Request $request): JsonResponse
    {
        abort_unless(StaffCapability::isStaff($request->user()), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'filters' => ['required', 'array'], 'filters.view' => ['nullable', 'string', 'max:30'], 'filters.q' => ['nullable', 'string', 'max:100'], 'filters.status' => ['nullable', 'string', 'max:30'],
            'filters.priority' => ['nullable', 'string', 'max:10'], 'filters.type' => ['nullable', 'string', 'max:40'], 'filters.sort' => ['nullable', 'string', 'max:10'], 'filters.layout' => ['nullable', 'string', 'max:10'],
        ]);
        abort_if(DB::table('saved_views')->where('user_id', $request->user()->id)->count() >= 20, 422, 'You can keep up to 20 views.');
        DB::table('saved_views')->updateOrInsert(['user_id' => $request->user()->id, 'name' => $data['name']], ['filters' => json_encode(array_filter($data['filters'], fn ($v) => $v !== null && $v !== '')), 'updated_at' => now(), 'created_at' => now()]);

        return $this->savedViews($request);
    }

    public function deleteView(Request $request, int $view): JsonResponse
    {
        DB::table('saved_views')->where('id', $view)->where('user_id', $request->user()->id)->delete();

        return $this->savedViews($request);
    }

    /**
     * Several tickets at once: owner, priority or a status step. Each ticket is decided on its own, so one that cannot move
     * (wrong status, missing data, no permission) never blocks the others, and the answer says which is which.
     */
    public function bulk(Request $request, \App\Services\Support\SupportWorkflow $workflow, \App\Services\Support\SupportTicketService $service): JsonResponse
    {
        $data = $request->validate([
            'refs' => ['required', 'array', 'min:1', 'max:50'], 'refs.*' => ['string', 'max:30'], 'action' => ['required', Rule::in(['assign', 'priority', 'transition'])],
            'assigned_to_id' => ['required_if:action,assign', 'nullable', 'integer'], 'priority' => ['required_if:action,priority', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'to' => ['required_if:action,transition', Rule::in(\App\Support\TicketStatus::LIFECYCLE)], 'reason' => ['nullable', 'string', 'max:2000'], 'waiting_reason' => ['nullable', 'string', 'max:500'], 'follow_up_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        abort_unless(StaffCapability::any($request->user()), 403);
        $results = [];
        foreach (array_unique($data['refs']) as $ref) {
            $ticket = Ticket::query()->where('ref', $ref)->first();
            if ($ticket === null || ! app(TicketAccess::class)->canView($request->user(), $ticket)) {
                $results[] = ['ref' => $ref, 'ok' => false, 'error' => 'Not found.'];

                continue;
            }
            try {
                match ($data['action']) {
                    'assign' => $service->assign($ticket, $request->user(), ['assigned_to_id' => $data['assigned_to_id']]),
                    'priority' => $service->changePriority($ticket, $request->user(), $data['priority']),
                    'transition' => $workflow->transition($ticket, $data['to'], $request->user(), $data),
                };
                $results[] = ['ref' => $ref, 'ok' => true];
            } catch (\Illuminate\Validation\ValidationException $e) {
                $results[] = ['ref' => $ref, 'ok' => false, 'error' => collect($e->errors())->flatten()->first()];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $results[] = ['ref' => $ref, 'ok' => false, 'error' => $e->getMessage() ?: 'Not allowed.'];
            }
        }

        return response()->json(['results' => $results, 'done' => collect($results)->where('ok', true)->count()]);
    }

    /** Open work per person: for spreading the queue fairly. Triage, delivery managers and admins. */
    public function workload(Request $request): JsonResponse
    {
        abort_unless(StaffCapability::manages($request->user()), 403);
        $open = Ticket::query()->whereNotNull('type')->whereNotIn('status', ['resolved', 'closed', 'rejected'])->whereNotNull('assigned_to_id');
        $rows = (clone $open)->selectRaw("assigned_to_id, count(*) as open, sum(case when priority = 'urgent' then 1 else 0 end) as critical, min(created_at) as oldest")->groupBy('assigned_to_id')->get()->keyBy('assigned_to_id');
        $breached = DB::table('ticket_sla_clocks')->join('tickets', 'tickets.id', '=', 'ticket_sla_clocks.ticket_id')->whereNull('met_at')->whereNotNull('breached_at')
            ->whereNotIn('tickets.status', ['resolved', 'closed', 'rejected'])->selectRaw('tickets.assigned_to_id, count(distinct tickets.id) as n')->groupBy('tickets.assigned_to_id')->pluck('n', 'assigned_to_id');
        $people = \App\Models\User::query()->whereIn('role', [Role::Admin, Role::Employee])->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role', 'staff_capabilities'])->filter(fn ($u) => StaffCapability::any($u));

        return response()->json([
            'people' => $people->map(fn ($u): array => ['id' => $u->id, 'name' => $u->name, 'open' => (int) ($rows[$u->id]->open ?? 0), 'critical' => (int) ($rows[$u->id]->critical ?? 0), 'breached' => (int) ($breached[$u->id] ?? 0), 'oldest' => $rows[$u->id]->oldest ?? null])->values(),
            'unassigned' => Ticket::query()->whereNotNull('type')->whereNull('assigned_to_id')->whereNotIn('status', ['resolved', 'closed', 'rejected'])->count(),
        ]);
    }
}
