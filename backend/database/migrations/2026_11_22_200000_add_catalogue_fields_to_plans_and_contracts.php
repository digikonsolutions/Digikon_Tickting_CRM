<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a plan says on the pricing page: a small heading, the "what's included" list and who it suits.
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('tagline', 60)->nullable()->after('name');
            $table->string('best_for', 255)->nullable()->after('description');
            $table->json('features')->nullable()->after('best_for');
            // The "Custom" entry: no fixed price or limits, the admin fills them in for each agency.
            $table->boolean('is_custom')->default(false)->after('is_active');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_custom');
        });

        // The inclusions are copied onto the contract like every other plan value (a snapshot).
        Schema::table('agency_contracts', function (Blueprint $table): void {
            $table->json('features')->nullable()->after('plan_name');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contracts', fn (Blueprint $table) => $table->dropColumn('features'));
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn(['tagline', 'best_for', 'features', 'is_custom', 'sort_order']));
    }
};