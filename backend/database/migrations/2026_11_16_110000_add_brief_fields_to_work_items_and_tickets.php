<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->text('objective')->nullable();
            $table->json('deliverables')->nullable();        // list of strings (checklist)
            $table->string('target_url', 500)->nullable();
            $table->string('staging_url', 500)->nullable();
            $table->string('repo_url', 500)->nullable();
            $table->string('platform', 20)->nullable();      // ghl | wordpress | custom | other
            $table->string('tech_stack', 255)->nullable();
            $table->string('brand_assets_url', 500)->nullable();
            $table->text('access_details')->nullable();      // encrypted by the model cast
            $table->json('reference_links')->nullable();     // list of URLs
            $table->text('acceptance_criteria')->nullable();
            $table->string('budget', 100)->nullable();
            $table->string('brief_priority', 10)->nullable(); // low | normal | high | urgent
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_email', 254)->nullable();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->text('steps_to_reproduce')->nullable();
            $table->text('expected_result')->nullable();
            $table->text('actual_result')->nullable();
            $table->string('affected_url', 500)->nullable();
            $table->string('environment', 255)->nullable();
            $table->string('severity', 10)->nullable();       // low | medium | high | critical
            $table->string('due_preference', 20)->nullable(); // asap | this_week | next_week | flexible
            $table->json('related_ticket_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['steps_to_reproduce', 'expected_result', 'actual_result', 'affected_url', 'environment', 'severity', 'due_preference', 'related_ticket_ids']);
        });
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn(['objective', 'deliverables', 'target_url', 'staging_url', 'repo_url', 'platform', 'tech_stack', 'brand_assets_url', 'access_details', 'reference_links', 'acceptance_criteria', 'budget', 'brief_priority', 'contact_name', 'contact_email']);
        });
    }
};
