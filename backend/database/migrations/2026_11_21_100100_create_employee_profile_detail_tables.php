<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('level', 20);
            $table->decimal('years_experience', 3, 1)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('employee_educations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('degree', 160);
            $table->string('institution', 160);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('description', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('employee_experiences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('company', 160);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('description', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('employee_certifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('issuer', 160)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('credential_url', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('original_name', 150);
            $table->string('path', 255);
            $table->string('disk', 30)->default('local');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->date('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            // The actor. Kept (nulled) if the actor is later deleted so the trail survives.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'employee_documents', 'employee_certifications', 'employee_experiences', 'employee_educations', 'employee_skills'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
