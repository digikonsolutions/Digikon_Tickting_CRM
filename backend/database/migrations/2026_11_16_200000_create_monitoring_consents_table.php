<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per employee per accepted policy version. Rows are never updated, so the history of
        // what each person accepted, and when, is kept.
        Schema::create('monitoring_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('policy_version', 32);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->unique(['user_id', 'policy_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_consents');
    }
};
