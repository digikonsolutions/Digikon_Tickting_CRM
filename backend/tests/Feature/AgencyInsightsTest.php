<?php

namespace Tests\Feature;

use App\Services\AgencyContracts;
use App\Services\AgencyInsights;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The contract-driven rules, tested on plain arrays because no plan or contract tables exist yet. */
class AgencyInsightsTest extends TestCase
{
    private function contract(array $overrides = []): array
    {
        return [
            'plan_name' => 'Growth',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'renews_on' => '2026-12-31',
            'auto_renew' => true,
            'payment_terms' => 'Net 30',
            'price' => 4800,
            'currency' => 'USD',
            'discount' => '10%',
            'notes' => 'Internal: asked for a discount twice',
            'document_available' => true,
            'seats_allowed' => 3,
            'hours_included' => 100,
            'open_projects_allowed' => 5,
            'client_accounts_allowed' => 4,
            ...$overrides,
        ];
    }

    public function test_without_a_contract_every_limit_is_unknown_not_zero(): void
    {
        $this->assertSame(['seats' => null, 'hours' => null, 'open_projects' => null, 'client_accounts' => null], AgencyContracts::limits(null));
        $this->assertNull(AgencyContracts::redactForClient(null));
        $this->assertSame([], AgencyInsights::contractAttention(null, 500));
    }

    public function test_limits_read_the_contract(): void
    {
        $this->assertSame(['seats' => 3, 'hours' => 100.0, 'open_projects' => 5, 'client_accounts' => 4], AgencyContracts::limits($this->contract()));
    }

    public function test_clients_never_get_price_payment_terms_discount_or_notes(): void
    {
        $redacted = AgencyContracts::redactForClient($this->contract());

        $this->assertSame(['plan_name', 'status', 'ends_on', 'renews_on', 'days_remaining'], array_keys($redacted));
        $encoded = json_encode($redacted);
        foreach (['price', 'payment_terms', 'Net 30', '4800', 'discount', 'notes', 'asked for a discount', 'currency', 'USD'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_days_remaining_counts_whole_days_and_goes_negative_after_the_end(): void
    {
        $today = CarbonImmutable::parse('2026-06-01');

        $this->assertSame(10, AgencyContracts::daysRemaining($this->contract(['ends_on' => '2026-06-11']), $today));
        $this->assertSame(0, AgencyContracts::daysRemaining($this->contract(['ends_on' => '2026-06-01']), $today));
        $this->assertSame(-3, AgencyContracts::daysRemaining($this->contract(['ends_on' => '2026-05-29']), $today));
        $this->assertNull(AgencyContracts::daysRemaining($this->contract(['ends_on' => null]), $today));
    }

    public function test_projected_run_out_uses_the_recent_burn_rate(): void
    {
        $today = CarbonImmutable::parse('2026-06-01');

        $this->assertSame('2026-06-11', AgencyContracts::projectedRunOut(20.0, 2.0, $today));   // 10 days left
        $this->assertSame('2026-06-12', AgencyContracts::projectedRunOut(21.0, 2.0, $today));   // rounds up
        $this->assertSame('2026-06-01', AgencyContracts::projectedRunOut(0.0, 2.0, $today));    // already used up
        $this->assertNull(AgencyContracts::projectedRunOut(20.0, 0.0, $today));                 // nothing is being used
        $this->assertNull(AgencyContracts::projectedRunOut(null, 2.0, $today));                 // no contract
    }

    #[DataProvider('hoursCases')]
    public function test_hours_attention_at_eighty_and_one_hundred_percent(float $used, ?string $expected): void
    {
        $items = AgencyInsights::contractAttention($this->contract(['ends_on' => '2027-12-31']), $used, CarbonImmutable::parse('2026-06-01'));
        $types = array_column($items, 'type');

        $this->assertSame($expected === null ? [] : [$expected], $types);
    }

    /** @return array<string, array{float, string|null}> */
    public static function hoursCases(): array
    {
        return ['under 80' => [79.0, null], 'at 80' => [80.0, 'hours_nearly_used'], 'at 99' => [99.0, 'hours_nearly_used'], 'at 100' => [100.0, 'hours_used_up'], 'over 100' => [130.0, 'hours_used_up']];
    }

    public function test_contract_end_attention_at_thirty_and_seven_days(): void
    {
        $today = CarbonImmutable::parse('2026-06-01');
        $types = fn (string $end): array => array_map(fn (array $i): string => $i['severity'].':'.$i['type'], AgencyInsights::contractAttention($this->contract(['ends_on' => $end, 'hours_included' => null]), 0, $today));

        $this->assertSame([], $types('2026-08-01'));                                   // 61 days
        $this->assertSame(['warning:contract_ends_soon'], $types('2026-07-01'));       // 30 days
        $this->assertSame(['warning:contract_ends_soon'], $types('2026-06-09'));       // 8 days
        $this->assertSame(['danger:contract_ends_soon'], $types('2026-06-08'));        // 7 days
        $this->assertSame(['danger:contract_ends_soon'], $types('2026-06-01'));        // today
        $this->assertSame([], $types('2026-05-30'));                                   // already ended
    }
}
