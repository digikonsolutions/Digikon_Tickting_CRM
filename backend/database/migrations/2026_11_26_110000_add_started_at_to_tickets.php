<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // First time the ticket became "in progress"; the queue shows it as what the employee is doing now.
            $table->timestamp('started_at')->nullable()->after('position');
        });

        DB::table('tickets')->where('status', 'in_progress')->whereNull('started_at')->update(['started_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });
    }
};
