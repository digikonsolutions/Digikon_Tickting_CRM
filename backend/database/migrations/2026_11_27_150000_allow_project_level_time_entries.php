<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A project without tickets can be worked on and timed directly: its entries have no ticket, only a project.
        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Entries logged straight on a project have no ticket to point to.
        \Illuminate\Support\Facades\DB::table('time_entries')->whereNull('ticket_id')->delete();
        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable(false)->change();
        });
    }
};
