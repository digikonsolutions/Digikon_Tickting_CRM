<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which work a browser record belongs to: the ticket the employee had in progress at that moment, or the
        // project when it is worked without tickets. Records outside working time are not stored at all.
        Schema::table('browser_history_events', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('work_item_id')->nullable()->after('ticket_id')->constrained()->nullOnDelete();
            $table->index(['ticket_id', 'occurred_at']);
        });

        // When the employee's browser extension last checked in, so the app can tell them whether it is connected.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('browser_extension_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('browser_extension_seen_at');
        });
        Schema::table('browser_history_events', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'occurred_at']);
            $table->dropConstrainedForeignId('work_item_id');
            $table->dropConstrainedForeignId('ticket_id');
        });
    }
};
