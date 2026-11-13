<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            // Null while a timer is running.
            $table->unsignedInteger('minutes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('source', 10)->default('manual'); // manual | timer
            $table->boolean('auto_stopped')->default(false);
            $table->string('stop_reason', 60)->nullable();
            // Why an admin changed or removed the entry (mandatory for admins).
            $table->string('modified_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'entry_date']);
            $table->index('ticket_id');
            $table->index(['user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
