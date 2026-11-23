<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the assigned employee started and finished the project themselves (tickets are optional).
        Schema::table('work_items', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'completed_at']);
        });
    }
};
