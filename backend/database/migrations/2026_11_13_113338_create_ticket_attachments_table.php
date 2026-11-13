<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_event_id')->nullable()->constrained('ticket_events')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploader_name', 120);
            $table->string('original_name', 255);
            $table->string('stored_path', 255);
            $table->string('disk', 40);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            // Attachments on an internal comment are internal: hidden from agency users everywhere.
            $table->boolean('is_internal')->default(false);
            $table->softDeletes();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ticket_id', 'deleted_at']);
            $table->index('comment_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
