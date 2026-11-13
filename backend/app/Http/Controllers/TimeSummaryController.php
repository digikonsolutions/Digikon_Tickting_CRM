<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logged versus scheduled versus estimated hours, aggregated in SQL per employee, agency or project.
 * Admins see everything; agency owners see only their own agency.
 */
class TimeSummaryController extends Controller
{
    private const GROUPS = [
        'employee' => ['column' => 'employee_id', 'table' => 'users', 'name' => 'name'],
        'agency' => ['column' => 'agency_id', 'table' => 'agencies', 'name' => 'name'],
        'project' => ['column' => 'project_id', 'table' => 'work_items', 'name' => 'title'],
    ];

    public function admin(Request $request): Response
    {
        return $this->summarise($request, ['employee', 'agency', 'project'], null);
    }

    public function agency(Request $request): Response
    {
        return $this->summarise($request, ['employee', 'project'], $request->user()->agency_id);
    }

    /**
     * @param list<string> $groups
     */
    private function summarise(Request $request, array $groups, ?int $agencyId): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['required', 'in:'.implode(',', $groups)],
            'format' => ['sometimes', 'in:json,csv'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        abort_if($from->diffInDays($to) > 366, 422, 'The date range may not exceed one year.');

        $group = self::GROUPS[$data['group_by']];
        // One row per ticket with its logged and scheduled hours inside the range (subqueries, no N+1) ...
        $facts = DB::table('tickets as t')
            ->join('work_items as wi', 'wi.id', '=', 't.work_item_id')
            ->where('t.status', '!=', 'cancelled')
            ->when($agencyId !== null, fn ($query) => $query->where('wi.agency_id', $agencyId))
            ->selectRaw(
                't.id AS ticket_id, t.estimated_hours, wi.employee_id, wi.agency_id, wi.id AS project_id, '
                .'COALESCE((SELECT SUM(te.minutes) FROM time_entries te WHERE te.ticket_id = t.id AND te.deleted_at IS NULL AND te.minutes IS NOT NULL AND te.entry_date >= ? AND te.entry_date <= ?), 0) / 60.0 AS logged, '
                .'COALESCE((SELECT SUM(ta.hours) FROM ticket_allocations ta WHERE ta.ticket_id = t.id AND ta.work_date >= ? AND ta.work_date <= ?), 0) AS scheduled',
                [$from->toDateString(), $to->toDateString(), $from->toDateString(), $to->toDateString()]
            );

        // ... then grouped once.
        $rows = DB::query()->fromSub($facts, 'f')
            ->leftJoin("{$group['table']} as g", 'g.id', '=', "f.{$group['column']}")
            ->selectRaw("g.id AS group_id, g.{$group['name']} AS group_name, SUM(f.logged) AS logged, SUM(f.scheduled) AS scheduled, SUM(CASE WHEN f.logged > 0 OR f.scheduled > 0 THEN f.estimated_hours ELSE 0 END) AS estimated")
            ->groupBy('g.id', "g.{$group['name']}")
            ->havingRaw('SUM(f.logged) > 0 OR SUM(f.scheduled) > 0')
            ->orderBy("g.{$group['name']}")
            ->get()
            ->map(fn ($row): array => $this->row($row))
            ->values();

        $totals = [
            'logged_hours' => round($rows->sum('logged_hours'), 2),
            'scheduled_hours' => round($rows->sum('scheduled_hours'), 2),
            'estimated_hours' => round($rows->sum('estimated_hours'), 2),
        ];
        $totals['utilization'] = $totals['scheduled_hours'] > 0 ? round($totals['logged_hours'] / $totals['scheduled_hours'] * 100, 1) : null;

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->csv($rows, $totals, $data['group_by'], $from, $to);
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'group_by' => $data['group_by'],
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $row): array
    {
        $logged = round((float) $row->logged, 2);
        $scheduled = round((float) $row->scheduled, 2);

        return [
            'group' => ['id' => $row->group_id, 'name' => $row->group_name ?? 'Removed'],
            'logged_hours' => $logged,
            'scheduled_hours' => $scheduled,
            'estimated_hours' => round((float) $row->estimated, 2),
            'utilization' => $scheduled > 0 ? round($logged / $scheduled * 100, 1) : null,
        ];
    }

    /** Streams a CSV. Cells that spreadsheets could run as formulas are neutralised. */
    private function csv($rows, array $totals, string $groupBy, CarbonImmutable $from, CarbonImmutable $to): Response
    {
        $safe = fn ($value): string => (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value)) ? "'".$value : (string) $value;

        return response()->streamDownload(function () use ($rows, $totals, $groupBy, $safe): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [ucfirst($groupBy), 'Logged hours', 'Scheduled hours', 'Estimated hours', 'Utilization %']);
            foreach ($rows as $row) {
                fputcsv($out, [$safe($row['group']['name']), $row['logged_hours'], $row['scheduled_hours'], $row['estimated_hours'], $row['utilization'] ?? '']);
            }
            fputcsv($out, ['Total', $totals['logged_hours'], $totals['scheduled_hours'], $totals['estimated_hours'], $totals['utilization'] ?? '']);
            fclose($out);
        }, "time-summary-{$from->toDateString()}-{$to->toDateString()}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
