<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * The single place that knows about plans and contracts.
 *
 * There are no plan or contract tables yet, so every agency has no contract and every method here returns the
 * "no contract" answer (null, or null limits). All dashboards and pages already render those states. When the
 * plans-and-contracts tables arrive, load them in forAgency() and return the array shape documented below;
 * nothing else needs to change.
 *
 * Contract shape: plan_name, status, starts_on, ends_on, renews_on (Y-m-d), auto_renew, payment_terms, price,
 * currency, document_available, seats_allowed, hours_included, open_projects_allowed, client_accounts_allowed.
 */
class AgencyContracts
{
    /** @return array<string, mixed>|null */
    public function forAgency(int $agencyId): ?array
    {
        return null;
    }

    /**
     * @param  list<int>  $agencyIds
     * @return array<int, array<string, mixed>|null>
     */
    public function summaries(array $agencyIds): array
    {
        $result = [];
        foreach ($agencyIds as $id) {
            $result[$id] = $this->forAgency($id);
        }

        return $result;
    }

    /** @return array{available: bool, with_contract: int, without_contract: int, expiring: list<array<string, mixed>>} */
    public function overview(int $totalAgencies): array
    {
        return ['available' => false, 'with_contract' => 0, 'without_contract' => $totalAgencies, 'expiring' => []];
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
