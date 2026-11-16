<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Polymorphic files. Used for project briefs; ticket files keep their richer ticket_attachments table.
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploader_name', 120)->nullable();
            $table->string('original_name', 150);
            $table->string('path', 255);   // UUID name on the private disk, never the user's filename
            $table->string('disk', 40);
            $table->string('mime', 120);   // detected from the content
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
