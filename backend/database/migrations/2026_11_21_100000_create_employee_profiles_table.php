<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Personal (admin only). The photo is not stored here: it reuses users.avatar_url (Cloudinary).
            $table->string('father_name', 120)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            // Encrypted at rest. Ciphertext is randomised, so uniqueness is enforced on cnic_hash (HMAC of the digits).
            $table->text('cnic')->nullable();
            $table->string('cnic_hash', 64)->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('personal_email', 254)->nullable();
            $table->string('current_address', 500)->nullable();
            $table->string('permanent_address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_relation', 60)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();

            // Public (shown to agency owners and clients)
            $table->string('headline', 120)->nullable();
            $table->string('bio', 1000)->nullable();
            $table->string('department', 20)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('availability', 20)->default('available');
            $table->string('preferred_hours', 120)->nullable();
            $table->string('english_level', 30)->nullable();
            $table->string('portfolio_url', 255)->nullable();
            $table->string('github_url', 255)->nullable();
            $table->string('linkedin_url', 255)->nullable();

            // Employment
            $table->string('employee_code', 20)->nullable()->unique();
            $table->date('join_date')->nullable();
            $table->string('employment_type', 20)->nullable();
            $table->string('employment_status', 20)->default('active');
            $table->date('probation_end_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->foreignId('team_lead_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('weekly_capacity_hours')->default(36);
            $table->json('checklist')->nullable();

            // Admin-only finance and notes
            $table->decimal('pay_rate', 10, 2)->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->text('bank_account')->nullable(); // encrypted
            $table->text('internal_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
