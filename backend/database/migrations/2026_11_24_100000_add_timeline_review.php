<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timeline review. Purely additive and safe for existing data:
     *
     * - `timeline_status` defaults to 'confirmed', so every project and ticket that exists today is treated as
     *   already confirmed (nothing is paused, moved or re-reviewed). New work is set to 'proposed' by the code
     *   when `scheduling.require_timeline_review` is on.
     * - "Provisional" allocations are NOT a column: an allocation is provisional exactly while its project's (or
     *   ticket's) timeline_status is not 'confirmed'. One source of truth, and nothing to keep in sync; the
     *   scheduler already counts all of a project's allocations as booked, so provisional hours reserve capacity.
     * - `timeline_events` is the activity log (who proposed, reviewed, edited, confirmed, with before/after).
     */
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table): void {
            $table->string('timeline_status', 20)->default('confirmed')->after('status');
            $table->timestamp('timeline_confirmed_at')->nullable()->after('timeline_status');
            $table->foreignId('timeline_confirmed_by')->nullable()->after('timeline_confirmed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('project_manager_id')->nullable()->after('timeline_confirmed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('timeline_review_due_at')->nullable()->after('project_manager_id');
            $table->timestamp('timeline_proposed_at')->nullable()->after('timeline_review_due_at');
            $table->index('timeline_status');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->string('timeline_status', 20)->default('confirmed')->after('status');
            $table->timestamp('timeline_confirmed_at')->nullable()->after('timeline_status');
            $table->foreignId('timeline_confirmed_by')->nullable()->after('timeline_confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('timeline_proposed_at')->nullable()->after('timeline_confirmed_by');
            $table->index('timeline_status');
        });

        Schema::create('timeline_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->string('subject_type', 10)->default('project');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_role', 30)->nullable();
            $table->string('type', 30);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['work_item_id', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_events');
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('timeline_confirmed_by');
            $table->dropColumn(['timeline_status', 'timeline_confirmed_at', 'timeline_proposed_at']);
        });
        Schema::table('work_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('timeline_confirmed_by');
            $table->dropConstrainedForeignId('project_manager_id');
            $table->dropColumn(['timeline_status', 'timeline_confirmed_at', 'timeline_review_due_at', 'timeline_proposed_at']);
        });
    }
};
