<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The service and regression checklists of the spec, as editable data. */
    private const LIBRARY = [
        'ghl_crm' => ['Contacts and custom fields', 'Tags', 'Pipeline stages', 'Opportunity creation and duplication', 'Assignment', 'Permissions', 'Data mapping'],
        'ghl_workflows' => ['Trigger and filters', 'Conditions and branches', 'Waits and timezone', 'Actions', 'Entry and re-entry', 'Exit and stop conditions', 'Duplicate prevention', 'DND and opt-out', 'Conflicts with other workflows', 'Message content and merge fields', 'Notifications'],
        'ai_agent' => ['Knowledge accuracy', 'Role and scope', 'No unsupported claims', 'Clarification handling', 'Human escalation', 'Appointment booking', 'Transfer', 'Fallback', 'Conversation edge cases', 'Voice latency, pronunciation and interruption', 'Call summary and recording settings'],
        'website_funnel' => ['Page loads', 'Links and buttons', 'Forms and validation', 'Thank-you page', 'CRM data capture', 'Calendar booking', 'Email and SMS confirmation', 'Tracking and pixels', 'Mobile, tablet and desktop', 'Performance', 'Console errors'],
        'integration' => ['Credentials and authentication', 'Endpoint and method', 'Payload and response', 'Error handling', 'Retries', 'Timeout', 'Duplicate prevention', 'Logs', 'Failure notifications', 'Sensitive data protection'],
        'regression' => ['The original behavior still works', 'The fix works under the reproduction scenario', 'Related workflow and integration paths were tested', 'No duplicate behavior was introduced', 'No unintended side effects', 'Critical scenarios were retested', 'Evidence is attached'],
    ];

    public function up(): void
    {
        Schema::create('qa_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('list', 30); // ghl_crm | ghl_workflows | ai_agent | website_funnel | integration | regression
            $table->string('label', 200);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['list', 'position']);
        });
        foreach (self::LIBRARY as $list => $labels) {
            foreach ($labels as $i => $label) {
                DB::table('qa_checklist_items')->insert(['list' => $list, 'label' => $label, 'position' => $i + 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create('ticket_qa_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result', 8); // pass | fail
            $table->text('note')->nullable();
            $table->string('evidence_url', 1000)->nullable();
            $table->timestamps();
            $table->index('ticket_id');
        });
        Schema::create('ticket_qa_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qa_run_id')->constrained('ticket_qa_runs')->cascadeOnDelete();
            $table->string('list', 30);
            $table->string('label', 200);
            $table->string('result', 6); // pass | fail | na
            $table->string('note', 500)->nullable();
        });

        Schema::create('ticket_bug_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('severity', 10)->nullable(); // critical | high | medium | low
            $table->string('component', 20)->nullable(); // workflow | crm | ai | website | integration | other
            $table->string('bug_status', 12)->default('open'); // open | in_progress | fixed | retest | closed
            $table->timestamps();
        });

        Schema::create('ticket_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 14); // scope | quote | delivery | complimentary
            $table->string('status', 10)->default('pending'); // pending | approved | rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 1000)->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'status']);
        });

        Schema::create('ticket_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('type', 10)->default('related'); // related | duplicate
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['ticket_id', 'related_ticket_id']);
        });
        Schema::create('ticket_watchers', function (Blueprint $table) {
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['ticket_id', 'user_id']);
        });

        // Values an admin changes on the settings screen; they override config/support.php.
        Schema::create('support_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value');
            $table->timestamps();
        });
        Schema::create('agency_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('included_revisions_per_month')->nullable();
            $table->unsignedInteger('support_minutes_per_month')->nullable();
            $table->timestamps();
        });
        Schema::create('recurring_ticket_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 160);
            $table->text('description');
            $table->text('expected_result')->nullable();
            $table->json('details')->nullable();
            $table->foreignId('end_client_id')->nullable()->constrained('end_clients')->nullOnDelete();
            $table->string('frequency', 8); // weekly | monthly
            $table->date('next_run_on');
            $table->boolean('active')->default(true);
            $table->foreignId('last_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamps();
            $table->index(['active', 'next_run_on']);
        });
    }

    public function down(): void
    {
        foreach (['recurring_ticket_schedules', 'agency_entitlements', 'support_settings', 'ticket_watchers', 'ticket_relations', 'ticket_approvals', 'ticket_bug_details', 'ticket_qa_run_items', 'ticket_qa_runs', 'qa_checklist_items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
