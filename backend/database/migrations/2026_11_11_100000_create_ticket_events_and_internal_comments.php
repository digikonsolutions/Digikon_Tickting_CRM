<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_role')->nullable();
            $table->string('type', 40);
            $table->string('visibility', 20)->default('public');
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ticket_id', 'id']);
        });

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false);
        });

        // Backfill: one created event per existing ticket
        $tickets = DB::table('tickets')
            ->leftJoin('users', 'users.id', '=', 'tickets.created_by')
            ->select([
                'tickets.id as ticket_id',
                'tickets.work_item_id',
                'tickets.created_by as actor_id',
                'users.name as actor_name',
                'users.role as actor_role',
                'tickets.created_at',
            ])
            ->get();

        foreach ($tickets as $ticket) {
            DB::table('ticket_events')->insert([
                'ticket_id' => $ticket->ticket_id,
                'work_item_id' => $ticket->work_item_id,
                'actor_id' => $ticket->actor_id,
                'actor_name' => $ticket->actor_name ?? 'System',
                'actor_role' => $ticket->actor_role ?? 'system',
                'type' => 'created',
                'visibility' => 'public',
                'payload' => json_encode([]),
                'created_at' => $ticket->created_at ?? now(),
            ]);
        }

        // Backfill: convert existing comments into comment events
        $comments = DB::table('ticket_comments')
            ->join('tickets', 'tickets.id', '=', 'ticket_comments.ticket_id')
            ->leftJoin('users', 'users.id', '=', 'ticket_comments.user_id')
            ->select([
                'ticket_comments.id as comment_id',
                'ticket_comments.ticket_id',
                'tickets.work_item_id',
                'ticket_comments.user_id as actor_id',
                'users.name as actor_name',
                'users.role as actor_role',
                'ticket_comments.created_at',
            ])
            ->get();

        foreach ($comments as $comment) {
            DB::table('ticket_events')->insert([
                'ticket_id' => $comment->ticket_id,
                'work_item_id' => $comment->work_item_id,
                'actor_id' => $comment->actor_id,
                'actor_name' => $comment->actor_name ?? 'System',
                'actor_role' => $comment->actor_role ?? 'system',
                'type' => 'comment',
                'visibility' => 'public',
                'payload' => json_encode(['comment_id' => $comment->comment_id]),
                'created_at' => $comment->created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });

        Schema::dropIfExists('ticket_events');
    }
};
