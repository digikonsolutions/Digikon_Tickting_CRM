<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            // login, logout, session_start, session_end, ticket_status_changed, ticket_comment_added,
            // project_status_changed, idle_detected (App\Enums\ActivityEvent).
            $table->string('event_type', 40);
            $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->index(['employee_id', 'occurred_at']);
            $table->index(['employee_id', 'event_type', 'occurred_at'], 'activity_employee_type_time_index');
        });

        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('last_heartbeat_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 20)->nullable(); // logout | timeout | superseded
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('idle_seconds')->default(0);
            $table->boolean('is_idle')->default(false);
            $table->timestamps();
            $table->index(['employee_id', 'started_at']);
            $table->index(['employee_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_sessions');
        Schema::dropIfExists('employee_activity_logs');
    }
};
