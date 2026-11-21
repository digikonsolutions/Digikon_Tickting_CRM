<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company details, one row per agency. The logo and brand colour live in agency_branding (the existing
        // Cloudinary-backed white-label settings), not here, so there is one place for each.
        Schema::create('agency_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name', 160)->nullable();
            $table->string('brand_name', 160)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('company_size', 30)->nullable();
            $table->text('tax_number')->nullable(); // encrypted
            $table->string('billing_email', 254)->nullable();
            $table->string('billing_contact_name', 120)->nullable();
            $table->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_notes')->nullable();
            // A line added to the invitation email, kept so a draft can send it when it is activated.
            $table->string('welcome_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('employees')->nullable();
            $table->decimal('hours_per_month', 8, 2)->nullable();
            $table->unsignedSmallInteger('max_open_projects')->nullable();
            $table->unsignedSmallInteger('max_client_accounts')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->decimal('overage_rate', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // A contract copies the plan's values when it is made (a snapshot), so editing a plan later never changes
        // an agency that already signed.
        Schema::create('agency_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('plan_name', 120)->nullable();
            $table->string('status', 20)->default('active'); // trial | active | paused | expired | cancelled
            $table->unsignedSmallInteger('employees')->nullable();
            $table->decimal('hours_per_month', 8, 2)->nullable();
            $table->unsignedSmallInteger('max_open_projects')->nullable();
            $table->unsignedSmallInteger('max_client_accounts')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->decimal('overage_rate', 8, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->date('trial_end_date')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            // Set to the agency id while the contract is a trial or active, NULL otherwise. The unique index then
            // allows at most one such contract per agency (NULLs never collide) on SQLite and MySQL alike.
            $table->unsignedBigInteger('live_agency_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['agency_id', 'status']);
        });

        Schema::create('contract_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_contract_id')->constrained('agency_contracts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('contract_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_contract_id')->constrained('agency_contracts')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('original_name', 150);
            $table->string('path', 255);
            $table->string('disk', 30)->default('local');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['contract_documents', 'contract_events', 'agency_contracts', 'plans', 'agency_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
