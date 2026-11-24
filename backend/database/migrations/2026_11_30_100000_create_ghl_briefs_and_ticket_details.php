<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The detailed GoHighLevel brief of a project. Each section is stored whole as JSON (the source of truth);
        // the columns below are copies of the fields that cards and filters need.
        Schema::create('work_item_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('client_name', 120)->nullable();
            $table->string('client_email', 254)->nullable();
            $table->string('client_phone', 40)->nullable();
            $table->string('company', 160)->nullable();
            $table->string('website', 500)->nullable();
            $table->string('ghl_location_id', 80)->nullable();
            $table->string('ghl_subaccount_name', 160)->nullable();
            $table->string('access_method', 20)->nullable();
            $table->string('plan_tier', 60)->nullable();
            $table->date('launch_date')->nullable();
            $table->string('deadline_flexibility', 20)->nullable();
            $table->string('billing_type', 20)->nullable();
            $table->string('budget_range', 100)->nullable();
            $table->string('priority', 10)->nullable();
            $table->json('client')->nullable();
            $table->json('ghl')->nullable();
            $table->json('scope')->nullable();
            $table->json('pipelines')->nullable();
            $table->json('contacts_data')->nullable();
            $table->json('automations')->nullable();
            $table->json('funnels')->nullable();
            $table->json('communications')->nullable();
            $table->json('calendars')->nullable();
            $table->json('payments')->nullable();
            $table->json('branding')->nullable();
            $table->json('handover')->nullable();
            $table->text('notes_client')->nullable();
            $table->text('notes_internal')->nullable(); // admins and the assigned employee only
            $table->unsignedTinyInteger('completeness')->default(0);
            $table->string('source', 16)->default('manual'); // manual | ghl_prefill
            $table->timestamp('ghl_synced_at')->nullable();
            $table->timestamps();
        });

        // What is specific to one ticket: a work order the employee can act on without asking.
        Schema::create('ticket_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('work_type', 40)->nullable();
            $table->string('brief_section', 40)->nullable();
            $table->json('labels')->nullable();
            $table->string('ghl_location_id', 80)->nullable();
            $table->boolean('inputs_ready')->nullable();
            $table->boolean('blocked')->default(false);
            $table->string('blocked_reason', 1000)->nullable();
            $table->string('waiting_on', 16)->nullable(); // client | employee | third_party
            $table->string('environment', 8)->nullable(); // test | live
            $table->boolean('affects_live_traffic')->nullable();
            $table->boolean('requires_approval')->nullable();
            $table->text('rollback_note')->nullable();
            $table->json('requirements')->nullable();
            $table->json('ghl_refs')->nullable();
            $table->json('inputs')->nullable();
            $table->json('depends_on')->nullable();
            $table->json('testing')->nullable();
            $table->text('notes_client')->nullable();
            $table->text('notes_internal')->nullable();
            $table->json('delivery')->nullable();
            $table->timestamp('delivery_submitted_at')->nullable();
            $table->json('review')->nullable();
            $table->unsignedSmallInteger('checklist_total')->default(0);
            $table->unsignedSmallInteger('checklist_done')->default(0);
            $table->unsignedTinyInteger('completeness')->default(0);
            $table->string('source', 16)->default('manual');
            $table->timestamps();
            $table->index('work_type');
        });

        Schema::create('ticket_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('text', 300);
            $table->boolean('done')->default(false);
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_checklist_items');
        Schema::dropIfExists('ticket_details');
        Schema::dropIfExists('work_item_briefs');
    }
};
