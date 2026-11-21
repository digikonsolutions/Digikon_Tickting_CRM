<?php

namespace App\Console\Commands;

use App\Models\TimelineEvent;
use App\Models\WorkItem;
use App\Services\TimelineNotifier;
use App\Services\TimelineReview;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Hourly: emails admins about timelines still waiting for review after each configured number of hours
 * (24 and 48 by default). Each threshold is sent once per proposal; the "Overdue review" badge and the urgent flag
 * are computed from the same dates by the API.
 */
class RemindTimelineReviews extends Command
{
    protected $signature = 'timeline:remind';

    protected $description = 'Remind admins about timelines that have waited too long for review';

    public function handle(TimelineReview $review, TimelineNotifier $notifier): int
    {
        $thresholds = collect(config('scheduling.review_reminder_hours', [24, 48]))->map(fn ($h): int => (int) $h)->sort()->values();
        $sent = 0;

        $projects = WorkItem::query()->whereIn('status', ['scheduled', 'in_progress'])->where(function ($query): void {
            $query->whereIn('timeline_status', TimelineReview::PENDING)
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('tickets')->whereColumn('tickets.work_item_id', 'work_items.id')->whereIn('tickets.timeline_status', TimelineReview::PENDING));
        })->get();

        foreach ($projects as $project) {
            $since = CarbonImmutable::parse($project->timeline_proposed_at ?? $project->created_at);
            $age = (int) $since->diffInHours(CarbonImmutable::now());
            // The highest threshold reached that has not been sent for this proposal yet.
            $due = $thresholds->filter(fn (int $h): bool => $age >= $h)->last();
            if ($due === null) {
                continue;
            }
            $already = TimelineEvent::query()->where('work_item_id', $project->id)->where('type', 'reminder_sent')
                ->where('created_at', '>=', $since)->where('note', "reminder:{$due}")->exists();
            if ($already) {
                continue;
            }
            $notifier->overdue($project->loadMissing(['agency', 'client', 'employee']), $age);
            $review->log($project, 'reminder_sent', null, ['note' => "reminder:{$due}", 'after' => ['age_hours' => $age]]);
            $sent++;
        }

        $this->info("Sent {$sent} timeline reminder(s).");

        return self::SUCCESS;
    }
}
