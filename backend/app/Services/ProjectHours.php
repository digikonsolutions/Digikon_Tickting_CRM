<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hours of one project against the hours of its tickets, and the admin's change of a single ticket's hours.
 * A project's total is its planned hours (or its requested hours until planned); cancelled tickets count for nothing.
 */
class ProjectHours
{
    public static function cap(): float
    {
        return (float) config('scheduling.max_project_hours', 180);
    }

    /**
     * @param  float|null  $allocated  tickets' hours when the caller already summed them (lists), else summed here
     * @return array{total: float, allocated: float, remaining: float, logged: float|null, by_service?: list<array<string, mixed>>}
     */
    public function summary(WorkItem $project, ?float $allocated = null, bool $detailed = false): array
    {
        $total = round($project->scheduledHours(), 2);
        $allocated ??= (float) $project->tickets()->where('status', '!=', 'cancelled')->sum('estimated_hours');
        $summary = ['total' => $total, 'allocated' => round($allocated, 2), 'remaining' => round(max(0, $total - $allocated), 2), 'logged' => null];
        if (! $detailed) {
            return $summary;
        }

        $summary['logged'] = round(((int) DB::table('time_entries')->join('tickets', 'tickets.id', '=', 'time_entries.ticket_id')
            ->where('tickets.work_item_id', $project->id)->whereNotNull('time_entries.minutes')->sum('time_entries.minutes')) / 60, 2);

        $perService = $project->tickets()->where('status', '!=', 'cancelled')->whereNotNull('project_service_id')
            ->selectRaw('project_service_id, SUM(estimated_hours) AS hours, COUNT(*) AS tickets')->groupBy('project_service_id')->get()->keyBy('project_service_id');
        $summary['general'] = round((float) $project->tickets()->where('status', '!=', 'cancelled')->whereNull('project_service_id')->sum('estimated_hours'), 2);
        $summary['by_service'] = $project->services->map(function ($service) use ($perService): array {
            $row = $perService->get($service->id);
            $ticketHours = round((float) ($row->hours ?? 0), 2);
            $estimate = $service->estimated_hours !== null ? (float) $service->estimated_hours : null;

            return [
                'id' => $service->id, 'key' => $service->service_key, 'label' => $service->label(), 'estimate' => $estimate,
                'ticket_hours' => $ticketHours, 'tickets' => (int) ($row->tickets ?? 0),
                'remaining' => $estimate === null ? null : round(max(0, $estimate - $ticketHours), 2),
            ];
        })->values()->all();

        return $summary;
    }

    /** Checks a new hours figure for one ticket against what it has logged and the project's room. Messages are for the admin. */
    public function validateChange(Ticket $ticket, WorkItem $project, float $hours): void
    {
        $logged = ((int) $ticket->timeEntries()->whereNotNull('minutes')->sum('minutes')) / 60;
        if ($hours + 0.0001 < $logged) {
            throw ValidationException::withMessages(['estimated_hours' => 'This ticket already has '.number_format($logged, 2, '.', '').' h logged. The hours cannot go below that.']);
        }
        $others = (float) $project->tickets()->where('status', '!=', 'cancelled')->where('id', '!=', $ticket->id)->sum('estimated_hours');
        $room = round($project->scheduledHours() - $others, 2);
        if (round($hours, 2) > $room + 0.0001) {
            throw ValidationException::withMessages(['estimated_hours' => 'The project only has '.number_format(max(0, $room), 2, '.', '').' h left for this ticket. Raise the project hours first.']);
        }
        if ($others + $hours > self::cap() + 0.0001) {
            throw ValidationException::withMessages(['estimated_hours' => 'A project cannot have more than '.self::cap().' h of tickets.']);
        }
    }
}
