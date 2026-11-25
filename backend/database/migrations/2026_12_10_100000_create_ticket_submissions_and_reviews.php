<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One numbered submission each time the employee sends the work for review; every version is kept.
        Schema::create('ticket_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('submitted_by')->constrained('users');
            $table->text('summary');
            $table->json('links')->nullable();
            $table->text('ghl_changes')->nullable();
            $table->text('test_steps')->nullable();
            $table->text('limitations')->nullable();
            $table->text('access_note')->nullable();
            $table->unsignedInteger('time_minutes')->nullable();
            $table->unsignedSmallInteger('checklist_done')->default(0);
            $table->unsignedSmallInteger('checklist_total')->default(0);
            // The agency only sees a submission once it was sent on to them (after the internal approval, or at once when the review is off).
            $table->boolean('visible_to_agency')->default(false);
            $table->timestamps();
            $table->unique(['ticket_id', 'version']);
        });

        Schema::create('ticket_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('ticket_submissions')->cascadeOnDelete();
            $table->string('stage', 10); // internal | agency
            $table->foreignId('reviewer_id')->constrained('users');
            $table->string('decision', 20); // approved | changes_requested
            $table->text('feedback')->nullable();
            $table->json('items')->nullable();
            $table->timestamps();
            $table->index(['submission_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_reviews');
        Schema::dropIfExists('ticket_submissions');
    }
};