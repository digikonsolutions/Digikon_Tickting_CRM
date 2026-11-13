<?php

use App\Models\Agency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('ticket_prefix', 6)->nullable()->unique();
            $table->unsignedInteger('ticket_sequence')->default(0);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->cascadeOnDelete();
            $table->unsignedInteger('number')->nullable();
        });

        // Backfill existing agencies
        $agencies = DB::table('agencies')->orderBy('id')->get();
        $usedPrefixes = [];
        foreach ($agencies as $agency) {
            $prefix = $this->generatePrefixForName($agency->name, $usedPrefixes);
            $usedPrefixes[] = $prefix;
            DB::table('agencies')->where('id', $agency->id)->update([
                'ticket_prefix' => $prefix,
            ]);
        }

        // Backfill tickets agency_id from work_items
        $tickets = DB::table('tickets')
            ->join('work_items', 'work_items.id', '=', 'tickets.work_item_id')
            ->select('tickets.id', 'work_items.agency_id')
            ->get();

        foreach ($tickets as $ticket) {
            DB::table('tickets')->where('id', $ticket->id)->update([
                'agency_id' => $ticket->agency_id,
            ]);
        }

        // Number existing tickets per agency in order of id
        foreach ($agencies as $agency) {
            $agencyTickets = DB::table('tickets')
                ->where('agency_id', $agency->id)
                ->orderBy('id')
                ->get(['id']);

            $sequence = 0;
            foreach ($agencyTickets as $t) {
                $sequence++;
                DB::table('tickets')->where('id', $t->id)->update([
                    'number' => $sequence,
                ]);
            }

            DB::table('agencies')->where('id', $agency->id)->update([
                'ticket_sequence' => $sequence,
            ]);
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->unique(['agency_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['agency_id', 'number']);
            $table->dropConstrainedForeignId('agency_id');
            $table->dropColumn('number');
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['ticket_prefix', 'ticket_sequence']);
        });
    }

    private function generatePrefixForName(string $name, array $existing): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
        if (strlen($clean) < 2) {
            $clean = str_pad($clean, 2, 'X');
        }
        $base = substr($clean, 0, min(4, strlen($clean)));
        if (strlen($base) < 2) {
            $base = str_pad($base, 2, 'X');
        }

        $candidate = $base;
        $suffix = 1;
        while (in_array($candidate, $existing, true)) {
            $suffixStr = (string) $suffix;
            $maxBaseLen = 6 - strlen($suffixStr);
            $candidate = substr($base, 0, $maxBaseLen).$suffixStr;
            $suffix++;
        }

        return $candidate;
    }
};
