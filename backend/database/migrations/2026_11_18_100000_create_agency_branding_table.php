<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_branding', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agency_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name', 120)->nullable();
            $table->string('tagline', 160)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_collapsed_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('accent_color', 7)->nullable();
            $table->enum('theme_mode', ['light', 'dark', 'system'])->default('light');
            $table->enum('sidebar_style', ['light', 'brand'])->default('light');
            $table->enum('corner_style', ['soft', 'rounded', 'sharp'])->default('rounded');
            $table->string('welcome_message', 240)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_branding');
    }
};
