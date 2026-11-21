<?php

namespace App\Console\Commands;

use App\Support\NameNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off clean-up of names saved before the form requests normalised them. Emails are never touched.
 * Run with --dry-run first: it prints every change and writes nothing.
 */
class NormalizeNames extends Command
{
    protected $signature = 'app:normalize-names {--dry-run : Show what would change without saving anything}';

    protected $description = 'Tidy user and agency names: trim, collapse spaces and fix clearly broken capitalisation';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changes = [];

        foreach ([['users', 'User', fn (string $name): string => NameNormalizer::person($name)], ['agencies', 'Agency', fn (string $name): string => NameNormalizer::organization($name)]] as [$table, $label, $normalize]) {
            DB::table($table)->select(['id', 'name'])->orderBy('id')->chunkById(200, function ($rows) use ($table, $label, $normalize, $dryRun, &$changes): void {
                foreach ($rows as $row) {
                    $new = $normalize((string) $row->name);
                    if ($new === '' || $new === $row->name) {
                        continue;
                    }
                    $changes[] = [$label, $row->id, $row->name, $new];
                    if (! $dryRun) {
                        DB::table($table)->where('id', $row->id)->update(['name' => $new]);
                    }
                }
            });
        }

        if ($changes === []) {
            $this->info('Every name is already tidy. Nothing to change.');

            return self::SUCCESS;
        }

        $this->table(['Type', 'ID', 'Current', $dryRun ? 'Would become' : 'Now'], $changes);
        $this->info($dryRun
            ? count($changes).' name(s) would change. Run again without --dry-run to apply.'
            : count($changes).' name(s) updated.');

        return self::SUCCESS;
    }
}
