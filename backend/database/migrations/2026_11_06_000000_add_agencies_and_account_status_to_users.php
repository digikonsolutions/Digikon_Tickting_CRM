<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('agency_id')->nullable()->constrained();
            $table->boolean('is_active')->default(true);
            $table->string('role')->default('employee')->change();
        });

        // Legacy client accounts are external users without agency assignments;
        // keep them out of the employee role until an admin links and activates them.
        DB::table('users')->where('role', 'client')->update([
            'role' => 'agency_member',
            'is_active' => false,
        ]);
        DB::table('users')->where('role', 'agency_owner')->whereNull('agency_id')->update([
            'is_active' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
            $table->dropColumn('is_active');
            $table->string('role')->default('client')->change();
        });

        Schema::dropIfExists('agencies');
    }
};
