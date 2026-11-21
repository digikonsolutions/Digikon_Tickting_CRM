<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purely additive.
     *
     * employee_assignments.source records who put an employee on an agency: 'admin' (the onboarding wizard) or
     * 'owner' (the owner added one themselves). Rows that exist today stay NULL and are treated as admin-assigned,
     * which is the cautious reading: it only ever stops an owner adding employees, never lets one in.
     *
     * agency_requests keeps what an owner or client asked for (more hours, an employee, an upgrade, a renewal) with
     * their notes, so it is still visible afterwards. Files attach through the existing polymorphic attachments table.
     */
    public function up(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table): void {
            $table->string('source', 10)->nullable()->after('status');
        });

        Schema::create('agency_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->index(['agency_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_requests');
        Schema::table('employee_assignments', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
