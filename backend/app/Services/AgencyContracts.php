<?php

namespace App\Services;

use App\Models\AgencyContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single place that turns contract rows into the array every dashboard and page reads. An agency with no
 * contract gets null, and a null limit means "no limit known", never "zero".
 *
 * Contract shape: plan_name, status, billing_cycle, starts_on, ends_on, renews_on (Y-m-d), trial_ends_on,
 * auto_renew, payment_terms, price, currency, discount, overage_rate, document_available, seats_allowed,
 * hours_included, open_projects_allowed, client_accounts_allowed.
 *
 * The agency's current contract is its trial or active one; failing that the most recent one. Limits only apply
 * while a contract is trial, active or paused: an expired or cancelled contract shows its dates but sets no limits.
 */
class AgencyContracts
{
    /** @return array<string, mixed>|null */
    public function forAgency(int $agencyId): ?array
    {
        return $this->summaries([$agencyId])[$agencyId] ?? null;
    }

    /**
     * One query for the contracts and one for their documents, however many agencies are asked about.
     *
     * @param  list<int>  $agencyIds
     * @return array<int, array<string, mixed>|null>
     */
    public function summaries(array $agencyIds): array
    {
        $result = array_fill_keys($agencyIds, null);
        if ($agencyIds === []) {
            return $result;
        }

        $contracts = AgencyContract::query()
            ->whereIn('agency_id', $agencyIds)
            ->orderByRaw('CASE WHEN live_agency_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('start_date')->orderByDesc('id')
            ->get()
            ->groupBy('agency_id')
            ->map(fn ($group) => $group->first());

        $withDocuments = DB::table('contract_documents')->whereIn('agency_contract_id', $contracts->pluck('id'))
            ->distinct()->pluck('agency_contract_id')->flip();

        foreach ($contracts as $agencyId => $contract) {
            $result[(int) $agencyId] = self::present($contract, $withDocuments->has($contract->id));
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function present(AgencyContract $contract, bool $hasDocument = false): array
    {
        $limitsApply = in_array($contract->status, ['trial', 'active', 'paused'], true);
        $start = $contract->start_date?->toDateString();
        $end = $contract->end_date?->toDateString();

        return [
            'id' => $contract->id,
            'plan_name' => $contract->plan_name,
            'features' => $contract->features ?? [],
            'status' => $contract->status,
            'billing_cycle' => $contract->billing_cycle,
            'starts_on' => $start,
            'ends_on' => $end,
            'renews_on' => $contract->auto_renew ? $end : null,
            'trial_ends_on' => $contract->trial_end_date?->toDateString(),
            'auto_renew' => $contract->auto_renew,
            'payment_terms' => $contract->payment_terms_days !== null ? 'Net '.$contract->payment_terms_days : null,
            'price' => $contract->price !== null ? (float) $contract->price : null,
            'currency' => $contract->currency,
            'discount' => $contract->discount_percent !== null ? rtrim(rtrim((string) $contract->discount_percent, '0'), '.').'%' : null,
            'overage_rate' => $contract->overage_rate !== null ? (float) $contract->overage_rate : null,
            'notice_period_days' => $contract->notice_period_days,
            'document_available' => $hasDocument,
            'seats_allowed' => $limitsApply ? $contract->employees : null,
            'hours_included' => $limitsApply && $contract->hours_per_month !== null ? (float) $contract->hours_per_month : null,
            'open_projects_allowed' => $limitsApply ? $contract->max_open_projects : null,
            'client_accounts_allowed' => $limitsApply ? $contract->max_client_accounts : null,
        ];
    }

    /**
     * What the "Contracts expiring" card shows: how many agencies have a live contract, and those ending within 30 days.
     *
     * @return array{available: bool, with_contract: int, without_contract: int, expiring: list<array<string, mixed>>}
     */
    public function overview(int $totalAgencies): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $with = (int) AgencyContract::query()->whereNotNull('live_agency_id')->count();
        $expiring = DB::table('agency_contracts as c')
            ->join('agencies as a', 'a.id', '=', 'c.agency_id')
            ->whereNotNull('c.live_agency_id')
            ->whereNotNull('c.end_date')
            ->whereDate('c.end_date', '>=', $today->toDateString())
            ->whereDate('c.end_date', '<=', $today->addDays(30)->toDateString())
            ->orderBy('c.end_date')->limit(5)
            ->get(['a.id as agency_id', 'a.name', 'c.plan_name', 'c.end_date'])
            ->map(fn (object $row): array => [
                'agency_id' => (int) $row->agency_id,
                'name' => $row->name,
                'plan_name' => $row->plan_name,
                'ends_on' => CarbonImmutable::parse($row->end_date)->toDateString(),
                'days_remaining' => (int) $today->diffInDays(CarbonImmutable::parse($row->end_date)->startOfDay(), false),
            ])->values()->all();

        return [
            'available' => true,
            'with_contract' => $with,
            'without_contract' => max(0, $totalAgencies - $with),
            'expiring' => $expiring,
        ];
    }

    /**
     * What each limit is for a contract. A null limit means "no limit known", never "zero".
     *
     * @param  array<string, mixed>|null  $contract
     * @return array{seats: int|null, hours: float|null, open_projects: int|null, client_accounts: int|null}
     */
    public static function limits(?array $contract): array
    {
        return [
            'seats' => isset($contract['seats_allowed']) ? (int) $contract['seats_allowed'] : null,
            'hours' => isset($contract['hours_included']) ? (float) $contract['hours_included'] : null,
            'open_projects' => isset($contract['open_projects_allowed']) ? (int) $contract['open_projects_allowed'] : null,
            'client_accounts' => isset($contract['client_accounts_allowed']) ? (int) $contract['client_accounts_allowed'] : null,
        ];
    }

    /** Whole days from today to the contract end (negative once it has ended), or null without an end date. */
    public static function daysRemaining(?array $contract, ?CarbonImmutable $today = null): ?int
    {
        if (empty($contract['ends_on'])) {
            return null;
        }
        $today ??= CarbonImmutable::now(config('app.timezone'))->startOfDay();

        return (int) $today->diffInDays(CarbonImmutable::parse($contract['ends_on'], config('app.timezone'))->startOfDay(), false);
    }

    /**
     * The only contract fields an agency client may see. Price, payment terms, discounts and notes are owner and
     * admin business and never leave through a client response.
     *
     * @param  array<string, mixed>|null  $contract
     * @return array<string, mixed>|null
     */
    public static function redactForClient(?array $contract): ?array
    {
        if ($contract === null) {
            return null;
        }

        return [
            'plan_name' => $contract['plan_name'] ?? null,
            'status' => $contract['status'] ?? null,
            'ends_on' => $contract['ends_on'] ?? null,
            'renews_on' => $contract['renews_on'] ?? null,
            'days_remaining' => self::daysRemaining($contract),
        ];
    }

    /** The day the included hours run out at the recent burn rate, or null when it cannot be known. */
    public static function projectedRunOut(?float $remainingHours, float $burnPerDay, ?CarbonImmutable $today = null): ?string
    {
        if ($remainingHours === null) {
            return null;
        }
        $today ??= CarbonImmutable::now(config('app.timezone'))->startOfDay();
        if ($remainingHours <= 0) {
            return $today->toDateString();
        }
        if ($burnPerDay <= 0) {
            return null;
        }

        return $today->addDays((int) ceil($remainingHours / $burnPerDay))->toDateString();
    }
}
