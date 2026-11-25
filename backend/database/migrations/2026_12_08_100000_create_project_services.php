<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The GoHighLevel services a project asks for, each with its own answers. A project can hold several.
        Schema::create('project_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->string('service_key', 40);
            $table->json('details')->nullable();
            $table->decimal('estimated_hours', 6, 2)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['work_item_id', 'service_key']);
        });

        // Every project that already names a service gets that one service, so old and new projects look alike.
        foreach (DB::table('work_items')->whereNotNull('service_type')->get(['id', 'service_type', 'details']) as $row) {
            DB::table('project_services')->insert(['work_item_id' => $row->id, 'service_key' => $row->service_type, 'details' => $row->details, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_services');
    }
};