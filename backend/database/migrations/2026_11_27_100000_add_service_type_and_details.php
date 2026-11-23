<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The chosen GHL service (see config/service_catalog.php) and the answers to its fields.
        foreach (['work_items', 'tickets'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('service_type', 40)->nullable();
                $table->json('details')->nullable();
            });
        }

        // "Request an employee": role, hours per week and duration, next to the free-text notes.
        Schema::table('agency_requests', function (Blueprint $table) {
            $table->json('details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agency_requests', function (Blueprint $table) {
            $table->dropColumn('details');
        });
        foreach (['tickets', 'work_items'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['service_type', 'details']);
            });
        }
    }
};
