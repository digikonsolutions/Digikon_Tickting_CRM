<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One response clock and one resolution clock per ticket. Business time only: due_at already accounts for hours,
        // holidays and pauses; a paused clock keeps the business minutes it still has left.
        Schema::create('ticket_sla_clocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12); // response | resolution
            $table->unsignedInteger('target_minutes');
            $table->timestamp('started_at');
            $table->timestamp('due_at');
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('remaining_minutes')->nullable(); // set while paused
            $table->timestamp('met_at')->nullable();
            $table->timestamp('at_risk_at')->nullable();
            $table->timestamp('breached_at')->nullable();
            $table->timestamps();
            $table->unique(['ticket_id', 'kind']);
            $table->index(['met_at', 'due_at']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('waiting_since')->nullable();
        });
        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('created_from_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropForeign(['created_from_ticket_id']);
            $table->dropColumn('created_from_ticket_id');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('waiting_since');
        });
        Schema::dropIfExists('ticket_sla_clocks');
    }
};
