<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One ticket entity with a `kind` (support, fulfillment, change_request). Existing rows become fulfillment tickets and
     * keep working untouched; support tickets have no project, so work_item_id, estimated_hours and position become optional.
     */
    public function up(): void
    {
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
        DB::table('ticket_sequences')->insert(['name' => 'tickets', 'value' => (int) config('support.first_number', 1000)]);

        Schema::create('end_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email', 254)->nullable();
            $table->string('external_ref', 80)->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('staff_capabilities')->nullable(); // triage, builder, qa, delivery_manager, finance
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('work_item_id')->nullable()->change();
            $table->decimal('estimated_hours', 6, 2)->nullable()->change();
            $table->unsignedInteger('position')->nullable()->change();
        });
        Schema::table('ticket_events', function (Blueprint $table) {
            $table->unsignedBigInteger('work_item_id')->nullable()->change();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('kind', 20)->default('fulfillment');
            $table->string('ref', 30)->nullable()->unique();
            $table->string('type', 40)->nullable();
            $table->foreignId('end_client_id')->nullable()->constrained('end_clients')->nullOnDelete();
            $table->string('service', 30)->nullable();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_team', 40)->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('requested_deadline')->nullable();
            $table->json('impact')->nullable(); // the answers to the impact questions
            $table->foreignId('related_project_id')->nullable()->constrained('work_items')->nullOnDelete(); // a reference only: never scheduled
            $table->timestamp('issue_started_at')->nullable();
            $table->string('scope_classification', 30)->nullable();
            $table->string('billing_status', 20)->nullable();
            $table->foreignId('scope_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scope_approved_at')->nullable();
            $table->string('waiting_reason', 500)->nullable();
            $table->string('waiting_kind', 20)->nullable(); // agency | third_party
            $table->date('follow_up_date')->nullable();
            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->string('resolution_category', 30)->nullable();
            $table->text('root_cause')->nullable();
            $table->string('converted_to_type', 20)->nullable();
            $table->unsignedBigInteger('converted_to_id')->nullable();
            $table->foreignId('created_from_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamp('secret_flagged_at')->nullable();
            $table->index(['agency_id', 'status']);
            $table->index(['kind', 'status']);
            $table->index(['assigned_to_id', 'status']);
            $table->index('priority');
            $table->index('type');
            $table->index('service');
            $table->index('due_date');
        });

        // Links (Loom, Drive, any URL) sit next to files in the same table.
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->string('kind', 10)->default('file');
            $table->string('url', 1000)->nullable();
            $table->string('title', 160)->nullable();
            $table->string('stored_path', 255)->nullable()->change();
            $table->string('disk', 40)->nullable()->change();
            $table->string('mime_type', 120)->nullable()->change();
            $table->unsignedBigInteger('size_bytes')->nullable()->change();
            $table->char('sha256', 64)->nullable()->change();
        });

        // Every existing ticket gets a ref, in id order, so the whole system has one reliable identifier.
        $prefix = (string) config('support.ticket_prefix', 'DG');
        $next = (int) config('support.first_number', 1000);
        foreach (DB::table('tickets')->orderBy('id')->pluck('id') as $id) {
            $next++;
            DB::table('tickets')->where('id', $id)->update(['ref' => "{$prefix}-{$next}"]);
        }
        DB::table('ticket_sequences')->where('name', 'tickets')->update(['value' => $next]);
    }

    public function down(): void
    {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->dropColumn(['kind', 'url', 'title']);
        });
        Schema::table('tickets', function (Blueprint $table) {
            foreach (['end_client_id', 'related_project_id', 'submitted_by_id', 'assigned_to_id', 'reviewer_id', 'scope_approved_by', 'created_from_ticket_id'] as $fk) {
                $table->dropForeign([$fk]);
            }
            $table->dropIndex(['agency_id', 'status']);
            $table->dropIndex(['kind', 'status']);
            $table->dropIndex(['assigned_to_id', 'status']);
            $table->dropIndex(['priority']);
            $table->dropIndex(['type']);
            $table->dropIndex(['service']);
            $table->dropIndex(['due_date']);
            $table->dropUnique(['ref']);
            $table->dropColumn(['kind', 'ref', 'type', 'end_client_id', 'related_project_id', 'service', 'submitted_by_id', 'assigned_to_id', 'assigned_team', 'reviewer_id', 'requested_deadline', 'impact', 'issue_started_at', 'scope_classification', 'billing_status', 'scope_approved_by', 'scope_approved_at', 'waiting_reason', 'waiting_kind', 'follow_up_date', 'reopened_count', 'first_response_at', 'resolved_at', 'closed_at', 'resolution_summary', 'resolution_category', 'root_cause', 'converted_to_type', 'converted_to_id', 'created_from_ticket_id', 'secret_flagged_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('staff_capabilities');
        });
        Schema::dropIfExists('end_clients');
        Schema::dropIfExists('ticket_sequences');
    }
};
