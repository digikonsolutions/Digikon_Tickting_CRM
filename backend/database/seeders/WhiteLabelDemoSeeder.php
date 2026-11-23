<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo only: two agencies with clearly different brands, an owner and a client each, and two employees nobody
 * holds yet. The password comes from DEMO_PASSWORD (no hardcoded passwords); the seeder does nothing without it.
 * Safe to run twice.
 */
class WhiteLabelDemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('DEMO_PASSWORD');
        if (! app()->environment('local') || blank($password)) {
            $this->command?->warn('WhiteLabelDemoSeeder skipped: it needs the local environment and DEMO_PASSWORD.');

            return;
        }

        $brands = [
            ['name' => 'Northwind Demo', 'slug' => 'northwind', 'display_name' => 'Northwind', 'color' => '#0F766E', 'tagline' => 'Websites that work as hard as you do.', 'owner' => 'owner@northwind.test', 'client' => 'client@northwind.test'],
            ['name' => 'Sunrise Demo', 'slug' => 'sunrise', 'display_name' => 'Sunrise', 'color' => '#C2410C', 'tagline' => 'Brighter campaigns, every morning.', 'owner' => 'owner@sunrise.test', 'client' => 'client@sunrise.test'],
        ];
        foreach ($brands as $brand) {
            $agency = Agency::query()->firstOrCreate(['slug' => $brand['slug']], ['name' => $brand['name'], 'status' => 'active']);
            $agency->branding()->updateOrCreate([], ['display_name' => $brand['display_name'], 'tagline' => $brand['tagline'], 'primary_color' => $brand['color'], 'support_email' => 'help@'.$brand['slug'].'.test']);
            foreach (['owner' => [Role::AgencyOwner, 'Owner'], 'client' => [Role::AgencyClient, 'Client']] as $key => [$role, $label]) {
                User::query()->updateOrCreate(['email' => $brand[$key]], [
                    'name' => $brand['display_name'].' '.$label, 'password' => $password, 'role' => $role, 'agency_id' => $agency->id, 'is_active' => true,
                ]);
            }
        }
        foreach (['demo.employee.one@example.test' => 'Demo Employee One', 'demo.employee.two@example.test' => 'Demo Employee Two'] as $email => $name) {
            User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'role' => Role::Employee, 'is_active' => true]);
        }
        $this->command?->info('Demo agencies ready: /a/northwind/login and /a/sunrise/login.');
    }
}