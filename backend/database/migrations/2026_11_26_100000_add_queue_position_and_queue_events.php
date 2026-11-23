<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            // 1-based place in the employee's queue of open projects; 0 for closed ones.
            $table->unsignedInteger('queue_position')->default(0)->after('status');
            $table->index(['employee_id', 'status', 'queue_position'], 'work_items_queue_index');
        });

        // Backfill: open projects per employee, oldest first.
        $positions = [];
        DB::table('work_items')
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereNotNull('employee_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'employee_id'])
            ->each(function ($row) use (&$positions): void {
                $positions[$row->employee_id] = ($positions[$row->employee_id] ?? 0) + 1;
                DB::table('work_items')->where('id', $row->id)->update(['queue_position' => $positions[$row->employee_id]]);
            });

        // Audit trail of everything that changes an employee's queue.
        Schema::create('queue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24); // added|reordered|rush_inserted|removed|replanned|conflict_rejected
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['employee_id', 'created_at']);
            $table->index(['agency_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_events');
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex('work_items_queue_index');
            $table->dropColumn('queue_position');
        });
    }
};
