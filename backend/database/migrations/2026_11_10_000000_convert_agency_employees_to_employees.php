<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'agency_employee')
            ->update([
                'role' => 'employee',
                'agency_id' => null,
            ]);
    }

    public function down(): void
    {
        throw new LogicException('Converting agency employees to shared employees cannot be reversed safely.');
    }
};
