<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgencyEmployeeRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_agency_employee_accounts_become_shared_employees(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $user = User::factory()->create(['role' => 'employee']);

        DB::table('users')->where('id', $user->id)->update([
            'role' => 'agency_employee',
            'agency_id' => $agency->id,
        ]);

        $migration = require database_path('migrations/2026_11_10_000000_convert_agency_employees_to_employees.php');
        $migration->up();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'employee',
            'agency_id' => null,
        ]);
    }
}
