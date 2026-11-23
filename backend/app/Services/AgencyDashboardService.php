<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;

/**
 * GET /api/agency/dashboard. Owners see the agency's contract, limits, usage and every held employee. Clients
 * get a reduced view: only the employees they hold and the projects they created, a redacted contract (no
 * price, payment terms, discounts or notes), and their own attention items. Every query is filtered by the
 * caller's agency id (see AgencyInsights), and a client's by their own id too.
 */
class AgencyDashboardService
{
    public function __construct(private AgencyInsights $insights, private AgencyContracts $contracts, private OwnerDashboardExtras $extras, private EmployeeSeats $seats) {}

    /** @return array<string, mixed> */
    public function payload(User $viewer): array
    {
        return $viewer->role === Role::AgencyOwner ? $this->owner($viewer) : $this->client($viewer);
    }

    /**
     * @return array{used: int|float, allowed: int|float|null, remaining: int|float|null, reached: bool, nearly: bool}
     */
    private function limit(int|float $used, int|float|null $allowed): array
    {
        return [
            'used' => $used,
            'allowed' => $allowed,
            'remaining' => $allowed !== null ? max(0, $allowed - $used) : null,
            'reached' => $allowed !== null && $used >= $allowed,
            'nearly' => $allowed !== null && $allowed > 0 && $used / $allowed >= 0.8,
        ];
    }

    /** @param  list<array<string, mixed>>  $employees */
    private function weekly(array $employees): array
    {
        $week = AgencyInsights::weekBounds();
        $budget = (float) config('scheduling.weekly_capacity_hours', 36);

        return [
            ...$week,
            'budget_per_employee' => $budget,
            'remaining_hours' => round(array_sum(array_map(fn (array $e): float => max(0.0, $budget - $e['used_hours']), $employees)), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function owner(User $owner): array
    {
        $agency = $owner->agency;
        $contract = $this->contracts->forAgency($agency->id);
        $limits = AgencyContracts::limits($contract);
        $usage = $this->insights->usage($agency->id);
        $counts = $this->insights->counts($agency->id);
        $employees = $this->insights->heldEmployees($agency->id);
        $hours = $this->limit($usage['month_hours'], $limits['hours']);
        $seatSummary = $this->seats->summary($agency->id);
        $employees = $this->extras->withDailyHours($agency->id, $employees);

        return [
            'role' => Role::AgencyOwner->value,
            'agency' => ['id' => $agency->id, 'name' => AgencyBrandingPresenter::displayName($agency), 'status' => $agency->status],
            'contract' => $contract === null ? null : [...$contract, 'days_remaining' => AgencyContracts::daysRemaining($contract)],
            'limits' => [
                'employees' => $this->limit($counts['employees_held'], $limits['seats']),
                'hours' => [
                    ...$hours,
                    'period_start' => $usage['period_start'],
                    'period_end' => $usage['period_end'],
                    'burn_rate_per_day' => $usage['burn_rate_per_day'],
                    'projected_run_out' => AgencyContracts::projectedRunOut($hours['remaining'], $usage['burn_rate_per_day']),
                ],
                'open_projects' => $this->limit($counts['open_projects'], $limits['open_projects']),
                'client_accounts' => $this->limit($counts['client_accounts'], $limits['client_accounts']),
            ],
            'weekly' => $this->weekly($employees),
            'usage' => ['week_hours' => $usage['week_hours'], 'month_hours' => $usage['month_hours'], 'last_14_days' => $usage['last_14_days']],
            'employees' => $employees,
            'attention' => $this->insights->attention($agency->id, null, '/agency/projects', $contract, $usage['month_hours']),
            'recent_projects' => $this->insights->recentProjects($agency->id, null, 5),
            // The redesigned owner dashboard reads these (one request instead of many).
            'seats' => $seatSummary,
            'kpis' => $this->extras->kpis($agency->id),
            'needs_attention' => $this->extras->needsAttention($agency->id, $contract, $usage['month_hours'], $employees, $seatSummary['used'], $seatSummary['seats']),
            'active_projects' => $this->extras->activeProjects($agency->id, 5),
            'activity' => $this->extras->activity($agency->id, 10),
        ];
    }

    /** @return array<string, mixed> */
    private function client(User $client): array
    {
        $agency = $client->agency;
        $contract = $this->contracts->forAgency($agency->id);
        $limits = AgencyContracts::limits($contract);
        $own = $this->insights->usage($agency->id, $client->id);
        $counts = $this->insights->counts($agency->id, $client->id);
        $employees = $this->insights->heldEmployees($agency->id, $client->id);
        // The hours pool belongs to the whole agency, so "remaining" is measured against the agency's use.
        $poolUsed = $limits['hours'] !== null ? $this->insights->usage($agency->id)['month_hours'] : 0.0;
        $pool = $this->limit($poolUsed, $limits['hours']);

        return [
            'role' => Role::AgencyClient->value,
            'agency' => ['id' => $agency->id, 'name' => AgencyBrandingPresenter::displayName($agency)],
            'contract' => AgencyContracts::redactForClient($contract),
            'limits' => [
                'employees' => ['used' => $counts['employees_held']],
                'hours' => [
                    'used' => $own['month_hours'],
                    'remaining' => $pool['remaining'],
                    'reached' => $pool['reached'],
                    'nearly' => $pool['nearly'],
                    'period_start' => $own['period_start'],
                    'period_end' => $own['period_end'],
                ],
                // The one project limit that exists today: open projects per client.
                'open_projects' => $this->limit($counts['open_projects'], (int) config('scheduling.max_open_projects_per_client', 3)),
            ],
            'weekly' => $this->weekly($employees),
            'employees' => $employees,
            'attention' => $this->insights->attention($agency->id, $client->id, '/agency/my-projects', $contract, $poolUsed),
            'recent_projects' => $this->insights->recentProjects($agency->id, $client->id, 5),
        ];
    }
}
