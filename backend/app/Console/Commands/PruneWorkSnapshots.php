<?php

namespace App\Console\Commands;

use App\Models\WorkSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneWorkSnapshots extends Command
{
    protected $signature = 'snapshots:prune';

    protected $description = 'Delete work snapshots (files and rows) older than the retention period.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('activity.snapshots.retention_days'));
        $removed = 0;
        WorkSnapshot::query()->where('captured_at', '<', $cutoff)->orderBy('id')->chunkById(200, function ($rows) use (&$removed): void {
            foreach ($rows as $row) {
                Storage::disk($row->disk)->delete($row->path);
                $row->delete();
                $removed++;
            }
        });
        $this->info("Removed {$removed} snapshot(s).");

        return self::SUCCESS;
    }
}