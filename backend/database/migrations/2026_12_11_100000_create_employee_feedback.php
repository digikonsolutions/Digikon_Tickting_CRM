<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What an agency owner thinks of an employee they held: ratings, words, and a private note for our team.
        Schema::create('employee_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('employee_assignments')->cascadeOnDelete();
            $table->foreignId('work_item_id')->nullable()->constrained('work_items')->nullOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->unsignedTinyInteger('rating');
            $table->json('ratings')->nullable(); // category => 1..5
            $table->string('would_again', 8); // yes | maybe | no
            $table->text('went_well')->nullable();
            $table->text('to_improve')->nullable();
            $table->json('highlights')->nullable();
            $table->json('concerns')->nullable();
            $table->text('private_note')->nullable(); // admins and the agency's owner only; never the employee
            $table->dateTime('editable_until');
            $table->string('status', 10)->default('published'); // published | removed
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removed_reason', 500)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index(['agency_id', 'employee_id']);
        });

        Schema::create('employee_feedback_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_id')->unique()->constrained('employee_feedback')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_feedback_replies');
        Schema::dropIfExists('employee_feedback');
    }
};
