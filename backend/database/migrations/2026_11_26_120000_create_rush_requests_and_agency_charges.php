<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table) {
            // Temporary assignments (extra team member for a rush request) end by themselves at this moment.
            $table->timestamp('expires_at')->nullable()->after('ended_at');
            $table->index(['status', 'expires_at']);
        });

        Schema::create('rush_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('type', 24); // priority_insert | extra_employee
            $table->foreignId('assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description');
            $table->text('skills_notes')->nullable(); // extra_employee: what kind of team member is needed
            $table->decimal('estimated_hours', 6, 2);
            $table->date('needed_by');
            $table->text('reason')->nullable();
            // pending | quoted | accepted | rejected | cancelled | fulfilled | expired
            $table->string('status', 16)->default('pending');
            // Money is stored as integer minor units (cents) with its currency code; never floats.
            $table->unsignedBigInteger('quoted_amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->foreignId('quoted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quote_expires_at')->nullable();
            $table->text('quote_note')->nullable();
            // Admin-only: the team member the admin picked for an extra_employee request. Never shown to the agency.
            $table->foreignId('proposed_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('fulfilled_work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->foreignId('temp_assignment_id')->nullable()->constrained('employee_assignments')->nullOnDelete();
            $table->timestamps();
            $table->index(['agency_id', 'status']);
            $table->index(['status', 'quote_expires_at']);
        });

        // The charge ledger: one row per accepted quote, ready to be invoiced. Nothing here moves money.
        Schema::create('agency_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rush_request_id')->nullable()->unique()->constrained('rush_requests')->nullOnDelete();
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 12)->default('unbilled'); // unbilled | invoiced | paid | void
            $table->timestamps();
            $table->index(['agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_charges');
        Schema::dropIfExists('rush_requests');
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
