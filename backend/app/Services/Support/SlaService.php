<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketSlaClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The response and resolution clocks of a ticket. All maths is business time (SlaCalculator). A clock pauses while the
 * ticket waits for the agency (when the policy says so) and keeps the business minutes it had left; resuming moves the
 * due time out by exactly that much. States: on_track, at_risk (75% of the window used), paused, breached, met.
 */
class SlaService
{
    public const KINDS = ['response', 'resolution'];

    public function __construct(private SlaCalculator $calendar) {}

    public function calendar(): SlaCalculator
    {
        return $this->calendar;
    }

    /** @return array{response: int, resolution: int} business minutes for a priority */
    public function targets(string $priority): array
    {
        $row = config("support.sla.{$priority}") ?? config('support.sla.normal');

        return ['response' => (int) $row['response'], 'resolution' => (int) $row['resolution']];
    }

    public function start(Ticket $ticket, ?CarbonInterface $at = null): void
    {
        $at = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $targets = $this->targets($ticket->priority);
        foreach (self::KINDS as $kind) {
            TicketSlaClock::query()->updateOrCreate(['ticket_id' => $ticket->id, 'kind' => $kind], [
                'target_minutes' => $targets[$kind], 'started_at' => $at, 'due_at' => $this->calendar->addBusinessMinutes($at, $targets[$kind]),
                'paused_at' => null, 'remaining_minutes' => null, 'met_at' => null, 'at_risk_at' => null, 'breached_at' => null,
            ]);
        }
    }

    private function running(Ticket $ticket)
    {
        return TicketSlaClock::query()->where('ticket_id', $ticket->id)->whereNull('met_at')->get();
    }

    public function pause(Ticket $ticket): void
    {
        if (! config('support.pause_while_waiting_for_agency', true)) {
            return;
        }
        $now = CarbonImmutable::now();
        foreach ($this->running($ticket)->whereNull('paused_at') as $clock) {
            $clock->update(['paused_at' => $now, 'remaining_minutes' => max(0, $this->calendar->businessMinutesBetween($now, $clock->due_at))]);
        }
    }

    public function resume(Ticket $ticket): void
    {
        $now = CarbonImmutable::now();
        foreach ($this->running($ticket)->whereNotNull('paused_at') as $clock) {
            $clock->update(['paused_at' => null, 'due_at' => $this->calendar->addBusinessMinutes($now, (int) $clock->remaining_minutes), 'remaining_minutes' => null]);
        }
    }

    public function met(Ticket $ticket, string $kind): void
    {
        TicketSlaClock::query()->where('ticket_id', $ticket->id)->where('kind', $kind)->whereNull('met_at')->update(['met_at' => now()]);
    }

    /** A reopened ticket gets a fresh resolution clock; the response clock stays as it was. */
    public function restartResolution(Ticket $ticket): void
    {
        $now = CarbonImmutable::now();
        $minutes = $this->targets($ticket->priority)['resolution'];
        TicketSlaClock::query()->updateOrCreate(['ticket_id' => $ticket->id, 'kind' => 'resolution'], [
            'target_minutes' => $minutes, 'started_at' => $now, 'due_at' => $this->calendar->addBusinessMinutes($now, $minutes),
            'paused_at' => null, 'remaining_minutes' => null, 'met_at' => null, 'at_risk_at' => null, 'breached_at' => null,
        ]);
    }

    /** A new priority means new targets, counted from the moment the ticket was created. */
    public function reprioritize(Ticket $ticket): void
    {
        $targets = $this->targets($ticket->priority);
        foreach ($this->running($ticket) as $clock) {
            $started = CarbonImmutable::instance($clock->started_at);
            $clock->update(['target_minutes' => $targets[$clock->kind], 'due_at' => $this->calendar->addBusinessMinutes($started, $targets[$clock->kind]), 'at_risk_at' => null, 'breached_at' => null, 'paused_at' => null, 'remaining_minutes' => null]);
        }
    }

    /** @return array{state: string, due_at: string|null, minutes_left: int|null, ratio: float} */
    public function state(TicketSlaClock $clock, ?CarbonInterface $now = null): array
    {
        $now = $now ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        if ($clock->met_at !== null) {
            return ['state' => 'met', 'due_at' => $clock->due_at?->toISOString(), 'minutes_left' => null, 'ratio' => 1.0];
        }
        if ($clock->paused_at !== null) {
            return ['state' => 'paused', 'due_at' => $clock->due_at?->toISOString(), 'minutes_left' => (int) $clock->remaining_minutes, 'ratio' => 0.0];
        }
        $left = $this->calendar->businessMinutesBetween($now, $clock->due_at);
        $target = max(1, (int) $clock->target_minutes);
        $ratio = 1 - ($left / $target);
        $state = match (true) {
            $now->greaterThan($clock->due_at) => 'breached',
            $ratio >= (float) config('support.at_risk_ratio', 0.75) => 'at_risk',
            default => 'on_track',
        };

        return ['state' => $state, 'due_at' => $clock->due_at->toISOString(), 'minutes_left' => $left, 'ratio' => round($ratio, 3)];
    }

    /** @return array<string, array{state: string, due_at: string|null, minutes_left: int|null, ratio: float}> */
    public function snapshot(Ticket $ticket, ?CarbonInterface $now = null): array
    {
        $out = [];
        $clocks = $ticket->relationLoaded('slaClocks') ? $ticket->slaClocks : TicketSlaClock::query()->where('ticket_id', $ticket->id)->get();
        foreach ($clocks as $clock) {
            $out[$clock->kind] = $this->state($clock, $now);
        }

        return $out;
    }
}
