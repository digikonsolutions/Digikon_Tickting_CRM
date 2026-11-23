<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // True from the moment an agency owner or client submits a project until an admin (or the assigned employee)
        // plans its days. While true the project holds no hours and its dates are only a placeholder window.
        Schema::table('work_items', function (Blueprint $table) {
            $table->boolean('awaiting_schedule')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('awaiting_schedule');
        });
    }
};
