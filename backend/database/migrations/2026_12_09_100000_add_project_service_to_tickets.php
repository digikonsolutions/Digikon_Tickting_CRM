<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which of the project's services a ticket is for. Null means general project work.
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('project_service_id')->nullable()->constrained('project_services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['project_service_id']);
            $table->dropColumn('project_service_id');
        });
    }
};