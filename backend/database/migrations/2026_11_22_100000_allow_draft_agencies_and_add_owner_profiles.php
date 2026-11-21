<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // agencies.status was a database enum (active, suspended). A plain string works the same on SQLite and
        // MySQL and lets "draft" in; the allowed values are validated in code (App\Models\Agency::STATUSES).
        Schema::table('agencies', function (Blueprint $table): void {
            $table->string('status', 20)->default('active')->change();
        });

        // Extra facts about an agency owner (used only for agency_owner accounts).
        Schema::create('owner_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('preferred_contact_method', 20)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_profiles');
    }
};
