<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extra facts about an agency client, in their own table (the same pattern as owner_profiles and
     * employee_profiles) so the users table stays about sign-in. Purely additive; nothing is backfilled.
     */
    public function up(): void
    {
        Schema::create('client_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name', 120)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('preferred_contact_method', 20)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('country', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_profiles');
    }
};
