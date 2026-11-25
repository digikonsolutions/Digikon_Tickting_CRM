<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketSlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The KPIs of the spec for a date range, from the data the module already keeps. Times are business minutes (support
 * hours), never wall-clock. Targets are the spec's: first-pass QA 90%, on-time delivery 95%, critical defects 0.
 */
class TicketKpis
{
    public const TARGETS = ['first_pass_qa' => 90, 'on_time' => 95, 'critical_defects' => 0];

    public function __construct(private SlaCalculator $calendar) {}

    /** @return array<string, mixed> */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tickets = Ticket::query()->whereNotNull('type')->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->with('agency:id,name')->get();
        $closedTickets = $tickets->filter(fn (Ticket $t): bool => $t->resolved_at !== null);

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'volume' => ['total' => $tickets->count(), 'by_agency' => $this->count($tickets, fn (Ticket $t) => $t->agency?->name ?? 'Unknown'), 'by_service' => $this->count($tickets, fn (Ticket $t) => config("support.services.{$t->service}") ?? 'Not set'), 'by_type' => $this->count($tickets, fn (Ticket $t) => config("support.types.{$t->type}.label")), 'by_priority' => $this->count($tickets, fn (Ticket $t) => config("support.priorities.{$t->priority}.label"))],
            'first_response_minutes' => $this->average($tickets->filter(fn (Ticket $t) => $t->first_response_at !== null)->map(fn (Ticket $t) => $this->calendar->businessMinutesBetween(CarbonImmutable::instance($t->created_at), CarbonImmutable::instance($t->first_response_at)))),
            'resolution_minutes' => $closedTickets->groupBy('priority')->map(fn (Collection $group) => $this->average($group->map(fn (Ticket $t) => $this->calendar->businessMinutesBetween(CarbonImmutable::instance($t->created_at), CarbonImmutable::instance($t->resolved_at)))))->all(),
            'sla_compliance_percent' => $this->slaCompliance($tickets),
            'reopen_rate_percent' => $closedTickets->count() ? round($closedTickets->where('reopened_count', '>', 0)->count() / $closedTickets->count() * 100, 1) : null,
            'first_pass_qa_percent' => $this->firstPassQa($tickets),
            'on_time_percent' => $this->onTime($closedTickets),
            'critical_defects' => $tickets->where('type', 'bug')->where('priority', 'urgent')->count(),
            'feedback_average' => (function () use ($tickets): ?float {
                $avg = \Illuminate\Support\Facades\DB::table('ticket_feedback')->whereIn('ticket_id', $tickets->pluck('id'))->avg('rating');

                return $avg === null ? null : round((float) $avg, 2);
            })(),
            'targets' => self::TARGETS,
        ];
    }

    /** @return array<string, int> */
    private function count(Collection $tickets, callable $key): array
    {
        return $tickets->countBy($key)->sortDesc()->all();
    }

    private function average(Collection $minutes): ?int
    {
        return $minutes->isEmpty() ? null : (int) round($minutes->avg());
    }

    private function slaCompliance(Collection $tickets): ?float
    {
        $clocks = TicketSlaClock::query()->whereIn('ticket_id', $tickets->pluck('id'))->get();
        $judged = $clocks->filter(fn (TicketSlaClock $c): bool => $c->met_at !== null || $c->breached_at !== null);
        if ($judged->isEmpty()) {
            return null;
        }
        $ok = $judged->filter(fn (TicketSlaClock $c): bool => $c->met_at !== null && $c->met_at->lessThanOrEqualTo($c->due_at) && $c->breached_at === null);

        return round($ok->count() / $judged->count() * 100, 1);
    }

    private function firstPassQa(Collection $tickets): ?float
    {
        $events = TicketEvent::query()->whereIn('ticket_id', $tickets->pluck('id'))->where('type', 'status_detail')->get();
        $passed = $events->filter(fn ($e) => ($e->payload['qa'] ?? null) === 'pass')->pluck('ticket_id')->unique();
        $failed = $events->filter(fn ($e) => ($e->payload['qa'] ?? null) === 'fail')->pluck('ticket_id')->unique();
        $judged = $passed->merge($failed)->unique();

        return $judged->isEmpty() ? null : round($passed->diff($failed)->count() / $judged->count() * 100, 1);
    }

    private function onTime(Collection $resolved): ?float
    {
        $dated = $resolved->filter(fn (Ticket $t): bool => $t->due_date !== null);

        return $dated->isEmpty() ? null : round($dated->filter(fn (Ticket $t): bool => $t->resolved_at->startOfDay()->lessThanOrEqualTo($t->due_date))->count() / $dated->count() * 100, 1);
    }
}
