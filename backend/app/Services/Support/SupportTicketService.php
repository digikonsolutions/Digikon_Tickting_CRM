<?php

namespace App\Services\Support;

use App\Models\Agency;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creating and routing support-module tickets. Never touches the planner: these tickets have no hours or project slots. */
class SupportTicketService
{
    public function __construct(
        private TicketNumberGenerator $numbers,
        private SecretScanner $scanner,
        private TicketEventRecorder $recorder,
    ) {}

    /**
     * The priority the impact answers point to. P1 (urgent) is reserved for a critical incident or an explicit confirmation.
     *
     * @param  array<string, mixed>  $impact  live_broken, revenue_affected, workaround
     */
    public static function suggestPriority(string $type, array $impact): string
    {
        if ($type === 'critical_incident') {
            return 'urgent';
        }
        $broken = (bool) ($impact['live_broken'] ?? false);
        $revenue = (bool) ($impact['revenue_affected'] ?? false);
        $workaround = (bool) ($impact['workaround'] ?? false);

        return match (true) {
            ($broken && $revenue && ! $workaround) => 'high',
            $broken || ($revenue && ! $workaround) => $workaround ? 'normal' : 'high',
            default => 'normal',
        };
    }

    /**
     * @param  array<string, mixed>  $data  validated by StoreSupportTicketRequest
     */
    public function create(User $user, array $data): Ticket
    {
        $type = (string) $data['type'];
        $config = config("support.types.{$type}");
        $priority = $data['priority'] ?? self::suggestPriority($type, $data['impact'] ?? []);
        if ($priority === 'urgent' && $type !== 'critical_incident' && empty($data['confirm_urgent'])) {
            throw ValidationException::withMessages(['priority' => 'Critical priority is only for a critical incident. Please confirm it, or choose another priority.']);
        }
        $kinds = $this->scanner->scan($data['title'], $data['description'], $data['expected_result'] ?? null, json_encode($data['details'] ?? []));

        return DB::transaction(function () use ($user, $data, $type, $config, $priority, $kinds): Ticket {
            $agency = Agency::query()->lockForUpdate()->findOrFail($user->agency_id);
            $number = $agency->ticket_sequence + 1;
            $agency->update(['ticket_sequence' => $number]);

            $ticket = Ticket::create([
                'agency_id' => $agency->id,
                'number' => $number,
                'created_by' => $user->id,
                'submitted_by_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'expected_result' => $data['expected_result'] ?? null,
                'priority' => $priority,
                'status' => 'new',
                'kind' => $config['kind'],
                'ref' => $this->numbers->next(),
                'type' => $type,
                'service' => $data['service'] ?? null,
                'end_client_id' => $data['end_client_id'] ?? null,
                'related_project_id' => $data['related_project_id'] ?? null,
                'requested_deadline' => $data['requested_deadline'] ?? null,
                'issue_started_at' => $data['issue_started_at'] ?? null,
                'impact' => $data['impact'] ?? null,
                'details' => $data['details'] ?? null,
                'assigned_team' => $config['team'],
                'secret_flagged_at' => $kinds !== [] ? now() : null,
            ]);
            $this->recorder->record($ticket, 'created', ['type' => $type], $user, 'public');
            if ($kinds !== []) {
                $this->recorder->record($ticket, 'secret_flagged', ['kinds' => $kinds], $user, 'internal');
            }

            return $ticket;
        });
    }

    /**
     * Owner, team and reviewer. A change of owner is recorded in the internal history with both names.
     *
     * @param  array<string, mixed>  $data
     */
    public function assign(Ticket $ticket, User $actor, array $data): Ticket
    {
        abort_unless(StaffCapability::manages($actor), 403, 'Only triage or a delivery manager can assign tickets.');

        return DB::transaction(function () use ($ticket, $actor, $data): Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $before = ['owner' => $locked->assignee?->name, 'team' => $locked->assigned_team, 'reviewer' => $locked->reviewer?->name];
            $changes = [];
            foreach (['assigned_to_id', 'reviewer_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $user = $data[$field] ? User::query()->find($data[$field]) : null;
                    if ($data[$field] && ($user === null || ! StaffCapability::isStaff($user) || ! $user->is_active)) {
                        throw ValidationException::withMessages([$field => 'Choose an active member of staff.']);
                    }
                    $changes[$field] = $user?->id;
                }
            }
            if (array_key_exists('assigned_team', $data)) {
                $changes['assigned_team'] = $data['assigned_team'];
            }
            $locked->forceFill($changes)->save();
            $locked->load(['assignee', 'reviewer']);
            $after = ['owner' => $locked->assignee?->name, 'team' => $locked->assigned_team, 'reviewer' => $locked->reviewer?->name];
            if ($before !== $after) {
                $this->recorder->record($locked, 'reassigned', ['from' => $before, 'to' => $after], $actor, 'internal');
            }

            return $locked;
        });
    }

    public function changePriority(Ticket $ticket, User $actor, string $priority, ?string $reason = null): Ticket
    {
        abort_unless(StaffCapability::manages($actor), 403, 'Only triage or a delivery manager can change the priority.');
        if ($ticket->priority === $priority) {
            return $ticket;
        }
        $from = $ticket->priority;
        $ticket->forceFill(['priority' => $priority])->save();
        $this->recorder->record($ticket, 'priority_changed', ['from' => $from, 'to' => $priority, 'from_label' => config("support.priorities.{$from}.label"), 'to_label' => config("support.priorities.{$priority}.label")], $actor, 'public');
        if ($reason) {
            $this->recorder->record($ticket, 'priority_reason', ['reason' => $reason], $actor, 'internal');
        }

        return $ticket;
    }
}
