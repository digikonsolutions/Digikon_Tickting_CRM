<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description');
            $table->decimal('estimated_hours', 6, 2);
            $table->date('start_date');
            $table->date('due_date');
            $table->string('status', 24)->default('scheduled');
            $table->timestamps();
            $table->index(['agency_id', 'status']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('work_item_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 5, 2);
            $table->timestamps();
            $table->unique(['work_item_id', 'work_date']);
            $table->index(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_allocations');
        Schema::dropIfExists('work_items');
    }
};
