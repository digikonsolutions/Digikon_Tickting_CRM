<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Independent QA: the checklist library (editable data), a recorded run with a result for every item it was given, and
 * the status change that follows. A pass resolves the ticket, a fail sends it back to the builder with the failure note.
 * Also the bug details (severity, component, bug status).
 */
class TicketQa
{
    public function __construct(private SupportWorkflow $workflow, private TicketEventRecorder $recorder) {}

    /** The lists that suit a ticket: its service's list plus the regression list for bugs. @return list<string> */
    public static function listsFor(Ticket $ticket): array
    {
        $byService = ['ghl' => ['ghl_crm', 'ghl_workflows'], 'ai' => ['ai_agent'], 'website_funnel' => ['website_funnel'], 'n8n_api' => ['integration']];
        $lists = $byService[$ticket->service] ?? [];
        if ($ticket->type === 'workflow_automation' && ! in_array('ghl_workflows', $lists, true)) {
            $lists[] = 'ghl_workflows';
        }
        if ($ticket->type === 'bug' || $ticket->reopened_count > 0) {
            $lists[] = 'regression';
        }

        return $lists === [] ? ['regression'] : $lists;
    }

    /** @return array<string, list<array{id: int, label: string}>> */
    public function library(?Ticket $ticket = null): array
    {
        $rows = DB::table('qa_checklist_items')->where('active', true)->when($ticket, fn ($q) => $q->whereIn('list', self::listsFor($ticket)))->orderBy('list')->orderBy('position')->get();

        return $rows->groupBy('list')->map(fn ($group) => $group->map(fn ($r): array => ['id' => $r->id, 'label' => $r->label])->values()->all())->all();
    }

    /**
     * @param  array{items: list<array{list: string, label: string, result: string, note?: string|null}>, note?: string|null, evidence_url?: string|null, resolution_summary?: string|null, resolution_category?: string|null}  $data
     */
    public function run(Ticket $ticket, User $actor, array $data): Ticket
    {
        abort_unless($ticket->status === 'qa', 422, 'This ticket is not waiting for QA.');
        abort_unless(StaffCapability::has($actor, 'qa'), 403, 'Only QA can record a QA result.');
        abort_if($ticket->reviewer_id !== null && $ticket->reviewer_id !== $actor->id && ! StaffCapability::has($actor, 'delivery_manager'), 403, 'This ticket is assigned to another reviewer.');
        $items = $data['items'];
        $failed = collect($items)->contains(fn (array $i): bool => $i['result'] === 'fail');
        $note = trim((string) ($data['note'] ?? ''));
        if ($failed && $note === '') {
            throw ValidationException::withMessages(['note' => 'Say what failed so the builder can fix it.']);
        }

        return DB::transaction(function () use ($ticket, $actor, $data, $items, $failed, $note): Ticket {
            $runId = DB::table('ticket_qa_runs')->insertGetId(['ticket_id' => $ticket->id, 'reviewer_id' => $actor->id, 'result' => $failed ? 'fail' : 'pass', 'note' => $note ?: null, 'evidence_url' => $data['evidence_url'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ticket_qa_run_items')->insert(array_map(fn (array $i): array => ['qa_run_id' => $runId, 'list' => $i['list'], 'label' => $i['label'], 'result' => $i['result'], 'note' => $i['note'] ?? null], $items));

            return $failed
                ? $this->workflow->transition($ticket, 'in_progress', $actor, ['failure_note' => $note])
                : $this->workflow->transition($ticket, 'resolved', $actor, ['resolution_summary' => $data['resolution_summary'] ?? null, 'resolution_category' => $data['resolution_category'] ?? null]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function history(Ticket $ticket): array
    {
        $runs = DB::table('ticket_qa_runs')->leftJoin('users', 'users.id', '=', 'ticket_qa_runs.reviewer_id')->where('ticket_id', $ticket->id)->orderByDesc('ticket_qa_runs.id')->get(['ticket_qa_runs.*', 'users.name as reviewer']);
        $items = DB::table('ticket_qa_run_items')->whereIn('qa_run_id', $runs->pluck('id'))->get()->groupBy('qa_run_id');

        return $runs->map(fn ($r): array => [
            'id' => $r->id, 'result' => $r->result, 'reviewer' => $r->reviewer, 'note' => $r->note, 'evidence_url' => $r->evidence_url, 'at' => $r->created_at,
            'items' => ($items[$r->id] ?? collect())->map(fn ($i): array => ['list' => $i->list, 'label' => $i->label, 'result' => $i->result, 'note' => $i->note])->values()->all(),
        ])->all();
    }

    /** @return array{severity: string|null, component: string|null, bug_status: string} */
    public function bug(Ticket $ticket): array
    {
        $row = DB::table('ticket_bug_details')->where('ticket_id', $ticket->id)->first();

        return ['severity' => $row->severity ?? null, 'component' => $row->component ?? null, 'bug_status' => $row->bug_status ?? 'open'];
    }

    /** @param  array{severity?: string|null, component?: string|null, bug_status?: string|null}  $data */
    public function saveBug(Ticket $ticket, User $actor, array $data): array
    {
        abort_unless(StaffCapability::has($actor, 'builder', 'qa', 'triage', 'delivery_manager'), 403);
        DB::table('ticket_bug_details')->updateOrInsert(['ticket_id' => $ticket->id], array_merge($this->bug($ticket), $data, ['updated_at' => now(), 'created_at' => now()]));
        $this->recorder->record($ticket, 'bug_updated', $data, $actor, 'internal');

        return $this->bug($ticket);
    }
}
