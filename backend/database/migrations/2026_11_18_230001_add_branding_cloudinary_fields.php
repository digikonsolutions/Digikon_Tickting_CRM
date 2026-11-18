<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agency_branding', function (Blueprint $table) {
            $table->string('logo_public_id')->nullable()->after('logo_path');
            $table->string('logo_url')->nullable()->after('logo_public_id');
            $table->string('logo_collapsed_public_id')->nullable()->after('logo_collapsed_path');
            $table->string('logo_collapsed_url')->nullable()->after('logo_collapsed_public_id');
            $table->string('favicon_public_id')->nullable()->after('favicon_path');
            $table->string('favicon_url')->nullable()->after('favicon_public_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agency_branding', function (Blueprint $table) {
            $table->dropColumn([
                'logo_public_id',
                'logo_url',
                'logo_collapsed_public_id',
                'logo_collapsed_url',
                'favicon_public_id',
                'favicon_url',
            ]);
        });
    }
};
?>