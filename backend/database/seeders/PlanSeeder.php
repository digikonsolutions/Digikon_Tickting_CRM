<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The plans on the pricing page, plus a Custom entry. Safe to run again: plans are matched by name and only created
 * when missing, so edits made on the Plans page survive and contracts keep their own copy anyway.
 *
 * "employees" is the number of people dedicated to the agency (builders plus a delivery manager). The pricing page
 * gives no hour or project limits, so those stay empty (no limit); edit a plan on the Plans page to add them.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::plans() as $order => $plan) {
            // Only missing plans are created: a plan an admin has edited (or retired) is never overwritten.
            Plan::firstOrCreate(['name' => $plan['name']], [...$plan, 'sort_order' => $order + 1, 'is_active' => true]);
        }
    }

    /** @return list<array<string, mixed>> */
    public static function plans(): array
    {
        $common = ['currency' => 'USD', 'billing_cycle' => 'monthly', 'hours_per_month' => null, 'max_open_projects' => null, 'max_client_accounts' => null, 'overage_rate' => null];

        return [
            [
                ...$common,
                'name' => 'Digikon Dedicated Builder',
                'tagline' => 'Dedicated monthly support',
                'description' => 'A dedicated GoHighLevel builder to set up your account, implement automations and improve your workflows. Monthly plan, cancel anytime.',
                'best_for' => 'Best for 1 GHL account or 5–10 sub-accounts.',
                'price' => 799,
                'employees' => 1,
                'is_custom' => false,
                'features' => [
                    '1 dedicated GoHighLevel builder',
                    'GoHighLevel account setup and configuration',
                    '1:1 Zoom onboarding session',
                    'Prebuilt AI agent setup',
                    'Workflow automation templates',
                    'Lead capture and follow-up workflow setup',
                    'Step-by-step training resources',
                    '1 n8n workflow or API integration per month',
                    'Workflow blueprint and recorded handoff',
                    '14-day fix promise',
                ],
            ],
            [
                ...$common,
                'name' => 'Agency AI Automation Partner',
                'tagline' => 'Scale delivery',
                'description' => "Extend your agency's delivery capacity with dedicated builders for ongoing client automation projects. Monthly plan, cancel anytime.",
                'best_for' => 'Best for agencies managing 10–20 sub-accounts.',
                'price' => 1499,
                'employees' => 2,
                'is_custom' => false,
                'features' => [
                    '1 dedicated automation builder + 1 delivery manager',
                    'GoHighLevel workflows and AI automation',
                    'n8n workflows and API integrations',
                    'Custom workflow design and implementation',
                    'Client project coordination and progress tracking',
                    'Workflow documentation and recorded handoffs',
                    'Monthly strategy and optimization calls',
                    'Shared Slack channel or ClickUp board',
                    '14-day fix promise',
                ],
            ],
            [
                ...$common,
                'name' => 'White Label AI Systems Pod',
                'tagline' => 'Expand capacity',
                'description' => 'Scale white-label automation delivery with a dedicated team supporting multiple client systems and complex integrations. Monthly plan, cancel anytime.',
                'best_for' => 'Best for agencies managing 20+ sub-accounts.',
                'price' => 3499,
                'employees' => 3,
                'is_custom' => false,
                'features' => [
                    '2 dedicated automation builders + 1 delivery manager',
                    'White-label GoHighLevel implementation',
                    'Advanced AI automation workflows',
                    'n8n, API, and third-party integrations',
                    'Multi-client workflow design and deployment',
                    'Reusable automation systems and templates',
                    'Workflow documentation and recorded handoffs',
                    'Coordinated project delivery and progress reporting',
                    'Monthly strategy and optimization calls',
                    'Shared Slack channel or ClickUp board',
                    '14-day fix promise',
                ],
            ],
            [
                ...$common,
                'name' => 'Custom plan',
                'tagline' => 'Tailored',
                'description' => 'Set your own price, limits and inclusions for this agency.',
                'best_for' => 'For agencies that need something different from the standard plans.',
                'price' => null,
                'employees' => null,
                'is_custom' => true,
                'features' => [],
            ],
        ];
    }
}