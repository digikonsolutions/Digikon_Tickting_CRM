<?php

use App\Models\Agency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * White-label portal. Additive:
     * - agencies.slug: the portal address (/a/{slug}), unique, backfilled from the agency name for existing rows.
     * - agency_branding.support_email / email_from_name: used for reply-to and the sender name of branded email.
     * The rest of the brand (display name, tagline, colour, logo, favicon) already lives in agency_branding.
     */
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table): void {
            $table->string('slug', 40)->nullable()->unique()->after('name');
        });
        Schema::table('agency_branding', function (Blueprint $table): void {
            $table->string('support_email', 254)->nullable();
            $table->string('email_from_name', 120)->nullable();
        });

        foreach (DB::table('agencies')->whereNull('slug')->orderBy('id')->get(['id', 'name']) as $row) {
            DB::table('agencies')->where('id', $row->id)->update(['slug' => Agency::generateSlug($row->name, $row->id)]);
        }
    }

    public function down(): void
    {
        Schema::table('agency_branding', fn (Blueprint $table) => $table->dropColumn(['support_email', 'email_from_name']));
        Schema::table('agencies', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
