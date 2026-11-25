<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Time spent on a support ticket. Staff only: agencies never see time or cost.
        Schema::create('ticket_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->unsignedInteger('minutes');
            $table->string('activity', 20)->default('work'); // work | qa | support
            $table->boolean('billable')->default(false);
            $table->string('note', 300)->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'entry_date']);
        });

        // One satisfaction answer per ticket, from the agency.
        Schema::create('ticket_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A builder may only propose a scope classification; a delivery manager approves it.
        Schema::table('tickets', function (Blueprint $table) {
            $table->json('scope_proposal')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('scope_proposal');
        });
        Schema::dropIfExists('canned_responses');
        Schema::dropIfExists('ticket_feedback');
        Schema::dropIfExists('ticket_time_entries');
    }
};
