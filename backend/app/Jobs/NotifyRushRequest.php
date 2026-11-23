<?php

namespace App\Jobs;

use App\Enums\Role;
use App\Models\RushRequest;
use App\Models\User;
use App\Models\WorkItem;
use App\Notifications\RushUpdated;
use App\Services\RushPresenter;
use App\Services\TicketNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Notifications for one step of a rush request, run by the queue worker.
 *
 * - submitted: admins (in-app + email).
 * - quoted: the agency owner (email + in-app, in the agency's brand).
 * - accepted / declined / expired: admins.
 * - rejected: the requester and the owner, in the agency's words (never the pool, never who decided).
 * - fulfilled: the employee (no money anywhere in it) and the requester.
 *
 * Agency recipients go through TicketNotifier, which keeps staff names and the platform brand out of the text.
 */
class NotifyRushRequest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $rushRequestId, public string $event, public ?int $actorId = null)
    {
        $this->afterCommit();
    }

    public function handle(TicketNotifier $notifier): void
    {
        $rush = RushRequest::query()->with(['agency', 'requester'])->find($this->rushRequestId);
        if ($rush === null) {
            return;
        }
        $title = $rush->title;
        $agency = $rush->agency?->name ?? 'An agency';
        $kind = $rush->type === 'priority_insert' ? 'an urgent request' : 'an additional team member';

        match ($this->event) {
            'submitted' => $this->toAdmins($notifier, "Urgent request from {$agency}", "{$rush->requester?->name} asks for {$kind}: “{$title}”, {$rush->estimated_hours} h, needed by {$rush->needed_by->toDateString()}.", $rush),
            'accepted' => $this->toAdmins($notifier, "Quote accepted by {$agency}", "“{$title}” was accepted at ".RushPresenter::money((int) $rush->quoted_amount_minor, (string) $rush->currency).'.', $rush),
            'declined' => $this->toAdmins($notifier, "Quote declined by {$agency}", "The quote for “{$title}” was declined.", $rush),
            'expired' => $this->toAdmins($notifier, "Quote expired for {$agency}", "The quote for “{$title}” expired without an answer.", $rush),
            'quoted' => $this->quoted($notifier, $rush),
            'rejected' => $this->rejected($notifier, $rush),
            'fulfilled' => $this->fulfilled($notifier, $rush),
            default => null,
        };
    }

    private function toAdmins(TicketNotifier $notifier, string $title, string $body, RushRequest $rush): void
    {
        foreach (User::query()->where('role', Role::Admin->value)->where('is_active', true)->get() as $admin) {
            $notifier->send($admin, RushUpdated::class, [
                'title' => $title,
                'body' => $body,
                'url_path' => '/admin/rush-requests',
                'actor_name' => null,
                'action_text' => 'Open urgent requests',
            ]);
        }
    }

    private function owners(RushRequest $rush)
    {
        return User::query()->where('agency_id', $rush->agency_id)->where('role', Role::AgencyOwner->value)->where('is_active', true)->get();
    }

    private function quoted(TicketNotifier $notifier, RushRequest $rush): void
    {
        $until = $rush->quote_expires_at?->timezone(config('app.timezone'))->format('M j, H:i');
        foreach ($this->owners($rush) as $owner) {
            $notifier->send($owner, RushUpdated::class, [
                'title' => "Quote ready: {$rush->title}",
                'body' => 'A quote of '.RushPresenter::money((int) $rush->quoted_amount_minor, (string) $rush->currency)." is ready for your urgent request “{$rush->title}”. It is valid until {$until}. Review it to accept or decline.",
                'url_path' => '/agency/queue?tab=rush',
                'action_text' => 'Review quote',
            ]);
        }
    }

    private function rejected(TicketNotifier $notifier, RushRequest $rush): void
    {
        $message = $rush->type === 'extra_employee' || blank($rush->rejection_reason)
            ? ($rush->type === 'extra_employee' ? RushPresenter::NO_ONE_AVAILABLE : 'This request could not be accommodated.')
            : $rush->rejection_reason;
        $recipients = $this->owners($rush)->push($rush->requester)->filter()->unique('id');
        foreach ($recipients as $user) {
            $notifier->send($user, RushUpdated::class, [
                'title' => "Update on your urgent request: {$rush->title}",
                'body' => $message,
                'url_path' => '/agency/queue?tab=rush',
                'action_text' => 'View request',
            ]);
        }
    }

    private function fulfilled(TicketNotifier $notifier, RushRequest $rush): void
    {
        $item = $rush->fulfilled_work_item_id === null ? null : WorkItem::query()->with('employee')->find($rush->fulfilled_work_item_id);
        if ($item?->employee !== null) {
            // Employees never see prices or rush amounts.
            $notifier->send($item->employee, RushUpdated::class, [
                'title' => "Urgent task added: {$item->title}",
                'body' => "“{$item->title}” was added to your queue and is due {$item->due_date->toDateString()}.",
                'url_path' => '/my-work',
                'project_id' => $item->id,
                'action_text' => 'Open my queue',
            ]);
        }
        if ($rush->requester !== null && $rush->requester->id !== $this->actorId) {
            $notifier->send($rush->requester, RushUpdated::class, [
                'title' => "Your urgent request is scheduled: {$rush->title}",
                'body' => 'It has been added to the plan and will be worked on first.',
                'url_path' => '/agency/queue?tab=rush',
                'action_text' => 'View request',
            ]);
        }
    }
}
