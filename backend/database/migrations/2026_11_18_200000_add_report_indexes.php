<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the report queries lean on. Existing ones already cover work_items(agency_id, status),
 * work_items(employee_id, status), work_item_allocations(employee_id, work_date) and employee_assignments
 * (agency_id, status) / (holder_id, status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->index(['client_id', 'status'], 'work_items_client_status_index');
            $table->index(['status', 'due_date'], 'work_items_status_due_index');
        });
        Schema::table('tickets', function (Blueprint $table): void {
            $table->index(['work_item_id', 'status'], 'tickets_work_item_status_index');
            $table->index('created_at', 'tickets_created_at_index');
            $table->index(['status', 'updated_at'], 'tickets_status_updated_index');
        });
        Schema::table('employee_assignments', function (Blueprint $table): void {
            $table->index(['employee_id', 'status'], 'employee_assignments_employee_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropIndex('work_items_client_status_index');
            $table->dropIndex('work_items_status_due_index');
        });
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_work_item_status_index');
            $table->dropIndex('tickets_created_at_index');
            $table->dropIndex('tickets_status_updated_index');
        });
        Schema::table('employee_assignments', function (Blueprint $table): void {
            $table->dropIndex('employee_assignments_employee_status_index');
        });
    }
};
