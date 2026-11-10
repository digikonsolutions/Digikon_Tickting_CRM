<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holder_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->unsignedBigInteger('active_employee_id')->nullable()->unique();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->foreign('active_employee_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['agency_id', 'status']);
            $table->index(['holder_id', 'status']);
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('assignment_id')
                ->nullable()
                ->after('client_id')
                ->constrained('employee_assignments')
                ->nullOnDelete();
            $table->index(['assignment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex(['assignment_id', 'status']);
            $table->dropConstrainedForeignId('assignment_id');
        });

        Schema::dropIfExists('employee_assignments');
    }
};
