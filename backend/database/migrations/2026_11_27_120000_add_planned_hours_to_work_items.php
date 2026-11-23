<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // estimated_hours stays what the agency asked for. planned_hours is what the admin scheduled day by day (null = same
        // as requested); planned_note is the admin's reason, shown to the agency when the two differ.
        Schema::table('work_items', function (Blueprint $table) {
            $table->decimal('planned_hours', 8, 2)->nullable();
            $table->text('planned_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn(['planned_hours', 'planned_note']);
        });
    }
};
