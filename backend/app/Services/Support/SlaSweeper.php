<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketEvent;
use App\Models\TicketSlaClock;
use App\Services\TicketEventRecorder;
use Carbon\CarbonImmutable;

/**
 * What the every-minute sweep does: flags clocks that are at risk or breached, escalates tickets nobody has picked up,
 * reminds agencies that a ticket waits on them, and closes resolved tickets the agency never answered. Each step writes
 * an event once (the event is its own marker), so running the sweep twice never notifies twice.
 */
class SlaSweeper
{
    public function __construct(private SlaService $sla, private TicketEventRecorder $recorder, private SupportWorkflow $workflow) {}

    /** @return array<string, int> what was done, for the command's output and for tests */
    public function sweep(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'at_risk' => $this->flagClocks($now, 'at_risk'),
            'breached' => $this->flagClocks($now, 'breached'),
            'unassigned' => $this->escalateUnassigned($now),
            'reminders' => $this->remindAgencies($now),
            'stalled' => $this->escalateStalled($now),
            'auto_closed' => $this->autoClose($now),
        ];
    }

    private function once(Ticket $ticket, string $type): bool
    {
        return ! TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', $type)->exists();
    }

    private function flagClocks(CarbonImmutable $now, string $wanted): int
    {
        $count = 0;
        $clocks = TicketSlaClock::query()->whereNull('met_at')->whereNull('paused_at')->whereNull($wanted === 'at_risk' ? 'at_risk_at' : 'breached_at')->with('ticket')->get();
        foreach ($clocks as $clock) {
            $ticket = $clock->ticket;
            if ($ticket === null || ! $ticket->usesLifecycle() || in_array($ticket->status, ['resolved', 'closed', 'rejected'], true)) {
                continue;
            }
            $state = $this->sla->state($clock, $now)['state'];
            if ($state === $wanted || ($wanted === 'at_risk' && $state === 'breached')) {
                $clock->update([$wanted === 'at_risk' ? 'at_risk_at' : 'breached_at' => $now]);
                if ($state === $wanted) {
                    $this->recorder->record($ticket, $wanted === 'at_risk' ? 'sla_at_risk' : 'sla_breached', ['kind' => $clock->kind, 'due_at' => $clock->due_at->toISOString()], null, 'internal');
                    $count++;
                }
            }
        }

        return $count;
    }

    private function escalateUnassigned(CarbonImmutable $now): int
    {
        $count = 0;
        foreach (Ticket::query()->whereNotNull('type')->whereNull('assigned_to_id')->whereIn('status', ['new', 'triage'])->get() as $ticket) {
            $limit = (int) (config("support.unassigned_escalation_minutes.{$ticket->priority}") ?? config('support.unassigned_escalation_minutes.default', 120));
            if ($this->sla->calendar()->businessMinutesBetween(CarbonImmutable::instance($ticket->created_at), $now) >= $limit && $this->once($ticket, 'escalated_unassigned')) {
                $this->recorder->record($ticket, 'escalated_unassigned', ['minutes' => $limit], null, 'internal');
                $count++;
            }
        }

        return $count;
    }

    private function waitingBusinessDays(Ticket $ticket, CarbonImmutable $now): float
    {
        $since = $ticket->waiting_since ?? $ticket->updated_at;
        $perDay = max(1, $this->sla->calendar()->businessMinutesPerDay());

        return $this->sla->calendar()->businessMinutesBetween(CarbonImmutable::instance($since), $now) / $perDay;
    }

    private function remindAgencies(CarbonImmutable $now): int
    {
        $count = 0;
        foreach (Ticket::query()->whereNotNull('type')->where('status', 'waiting_agency')->get() as $ticket) {
            if ($this->waitingBusinessDays($ticket, $now) >= (int) config('support.waiting_agency_reminder_business_days', 2) && $this->once($ticket, 'waiting_reminder')) {
                $this->recorder->record($ticket, 'waiting_reminder', ['reason' => $ticket->waiting_reason], null, 'public');
                $count++;
            }
        }

        return $count;
    }

    private function escalateStalled(CarbonImmutable $now): int
    {
        $count = 0;
        foreach (Ticket::query()->whereNotNull('type')->where('status', 'waiting_agency')->get() as $ticket) {
            if ($this->waitingBusinessDays($ticket, $now) >= (int) config('support.waiting_agency_stalled_business_days', 5) && $this->once($ticket, 'waiting_stalled')) {
                $this->recorder->record($ticket, 'waiting_stalled', [], null, 'internal');
                $count++;
            }
        }

        return $count;
    }

    private function autoClose(CarbonImmutable $now): int
    {
        $count = 0;
        $cutoff = $now->subDays((int) config('support.auto_close_days', 7));
        foreach (Ticket::query()->whereNotNull('type')->where('status', 'resolved')->where('resolved_at', '<=', $cutoff)->get() as $ticket) {
            $answered = TicketComment::query()->where('ticket_id', $ticket->id)->where('is_internal', false)->where('created_at', '>', $ticket->resolved_at)
                ->whereHas('user', fn ($q) => $q->whereIn('role', ['agency_owner', 'agency_client', 'agency_member']))->exists();
            if (! $answered) {
                $this->workflow->autoClose($ticket);
                $count++;
            }
        }

        return $count;
    }
}
