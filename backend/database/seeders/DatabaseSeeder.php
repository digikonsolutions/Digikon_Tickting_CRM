<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reference data every environment needs.
        $this->call(PlanSeeder::class);

        if (app()->environment('local')) {
            $this->call(LocalAdminSeeder::class);
        }
    }
}
