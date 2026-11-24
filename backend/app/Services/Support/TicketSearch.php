<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Support\TicketStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters shared by the agency list and the staff queue. The caller scopes the query to what the viewer may see first;
 * this only narrows it. `$internal` lets staff search internal notes too, an agency search never touches them.
 */
class TicketSearch
{
    /** @param  array<string, mixed>  $f */
    public function apply(Builder $query, array $f, bool $internal): Builder
    {
        $like = null;
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(function (Builder $w) use ($like, $internal): void {
                $w->where('ref', 'like', $like)->orWhere('title', 'like', $like)->orWhere('description', 'like', $like)
                    ->orWhereHas('comments', function (Builder $c) use ($like, $internal): void {
                        $c->where('body', 'like', $like);
                        if (! $internal) {
                            $c->where('is_internal', false);
                        }
                    });
            });
        }

        return $query
            ->when($f['tab'] ?? null, fn (Builder $b, string $tab) => match ($tab) {
                'open' => $b->whereNotIn('status', [...TicketStatus::stored('resolved'), ...TicketStatus::stored('closed'), ...TicketStatus::stored('rejected')]),
                'waiting' => $b->whereIn('status', TicketStatus::stored('waiting_agency')),
                'resolved' => $b->whereIn('status', [...TicketStatus::stored('resolved'), ...TicketStatus::stored('closed')]),
                default => $b,
            })
            ->when($f['status'] ?? null, fn (Builder $b, string $s) => $b->whereIn('status', TicketStatus::stored($s)))
            ->when($f['end_client_id'] ?? null, fn (Builder $b, $v) => $b->where('end_client_id', $v))
            ->when($f['project_id'] ?? null, fn (Builder $b, $v) => $b->where(fn (Builder $x) => $x->where('related_project_id', $v)->orWhere('work_item_id', $v)))
            ->when($f['type'] ?? null, fn (Builder $b, $v) => $b->where('type', $v))
            ->when($f['kind'] ?? null, fn (Builder $b, $v) => $b->where('kind', $v))
            ->when($f['priority'] ?? null, fn (Builder $b, $v) => $b->where('priority', $v))
            ->when($f['service'] ?? null, fn (Builder $b, $v) => $b->where('service', $v))
            ->when($f['agency_id'] ?? null, fn (Builder $b, $v) => $b->where('agency_id', $v))
            ->when($f['assigned_to_id'] ?? null, fn (Builder $b, $v) => $b->where('assigned_to_id', $v))
            ->when($f['team'] ?? null, fn (Builder $b, $v) => $b->where('assigned_team', $v))
            ->when(! empty($f['unassigned']), fn (Builder $b) => $b->whereNull('assigned_to_id')->whereIn('status', ['new', 'triage']))
            ->when($f['from'] ?? null, fn (Builder $b, $v) => $b->whereDate('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn (Builder $b, $v) => $b->whereDate('created_at', '<=', $v));
    }

    /**
     * Counts for tabs and KPI cards, in one grouped query, mapped to lifecycle statuses.
     *
     * @return array<string, int>
     */
    public function statusCounts(Builder $scope): array
    {
        $counts = [];
        foreach ((clone $scope)->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') as $status => $n) {
            $key = TicketStatus::lifecycle((string) $status);
            $counts[$key] = ($counts[$key] ?? 0) + (int) $n;
        }

        return $counts;
    }

    /** @param  array<string, int>  $counts */
    public function tabCounts(array $counts): array
    {
        $sum = fn (array $keys): int => array_sum(array_map(fn (string $k): int => $counts[$k] ?? 0, $keys));

        return [
            'open' => $sum(['new', 'triage', 'assigned', 'in_progress', 'waiting_agency', 'waiting_third_party', 'qa']),
            'waiting' => $sum(['waiting_agency']),
            'resolved' => $sum(['resolved', 'closed']),
            'all' => array_sum($counts),
        ];
    }
}
