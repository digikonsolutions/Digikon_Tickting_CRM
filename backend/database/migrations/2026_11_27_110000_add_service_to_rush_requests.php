<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The GHL service an urgent request is for (config/service_catalog.php) and the answers to its questions.
        Schema::table('rush_requests', function (Blueprint $table) {
            $table->string('service_type', 40)->nullable();
            $table->json('details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rush_requests', function (Blueprint $table) {
            $table->dropColumn(['service_type', 'details']);
        });
    }
};
