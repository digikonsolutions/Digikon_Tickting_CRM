<?php

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\User;
use App\Services\AgencyBrandingPresenter;
use App\Support\TicketStatus;

/**
 * The two audiences of a ticket, each built from an allow-list. Nothing is copied over with toArray(), so a column added
 * later can never reach an agency user by accident. Agency users get the status, a soft target and the public parts;
 * internal notes, time, cost, scope and billing calls, QA, SLA flags and who exactly is on the team are staff only.
 */
class TicketPresenter
{
    /** @return array<string, mixed> the fields both audiences see on a list row */
    private static function common(Ticket $t): array
    {
        $lifecycle = TicketStatus::lifecycle($t->status);

        return [
            'id' => $t->id,
            'ref' => $t->ref,
            'key' => $t->key,
            'kind' => $t->kind,
            'type' => $t->type,
            'type_label' => config("support.types.{$t->type}.label"),
            'title' => $t->title,
            'status' => $lifecycle,
            'status_label' => TicketStatus::label($t->status),
            'priority' => $t->priority,
            'priority_label' => config("support.priorities.{$t->priority}.label", ucfirst((string) $t->priority)),
            'service' => $t->service,
            'service_label' => config("support.services.{$t->service}"),
            'end_client' => $t->end_client_id && $t->relationLoaded('endClient') ? ['id' => $t->endClient?->id, 'name' => $t->endClient?->name] : null,
            'project' => $t->related_project_id && $t->relationLoaded('relatedProject') ? ['id' => $t->relatedProject?->id, 'title' => $t->relatedProject?->title] : null,
            'due_date' => $t->due_date?->toDateString(),
            'created_at' => $t->created_at?->toISOString(),
            'updated_at' => $t->updated_at?->toISOString(),
            'is_legacy' => ! $t->usesLifecycle(),
        ];
    }

    /** @return array<string, mixed> */
    public static function agencySummary(Ticket $t): array
    {
        return self::common($t) + ['waiting_on_you' => TicketStatus::lifecycle($t->status) === 'waiting_agency'];
    }

    /** @return array<string, mixed> */
    public static function agency(Ticket $t, User $viewer): array
    {
        $brand = $t->relationLoaded('agency') && $t->agency ? AgencyBrandingPresenter::displayName($t->agency).' Support' : 'Support';
        $workflow = app(SupportWorkflow::class);
        $finished = in_array(TicketStatus::lifecycle($t->status), ['resolved', 'closed', 'rejected'], true);

        return self::agencySummary($t) + [
            'description' => $t->description,
            'expected_result' => $t->expected_result,
            'details' => $t->details,
            'requested_deadline' => $t->requested_deadline?->toDateString(),
            // The owner by name, as the spec asks; unassigned tickets show the brand's support team.
            'owner' => $t->usesLifecycle() ? ['name' => $t->relationLoaded('assignee') && $t->assignee ? $t->assignee->name : $brand] : null,
            'waiting' => TicketStatus::lifecycle($t->status) === 'waiting_agency' ? ['reason' => $t->waiting_reason, 'follow_up_date' => $t->follow_up_date?->toDateString()] : null,
            'resolution' => $finished ? [
                'summary' => $t->resolution_summary,
                'category' => $t->resolution_category,
                'category_label' => config("support.resolution_categories.{$t->resolution_category}"),
                'at' => ($t->closed_at ?? $t->resolved_at)?->toISOString(),
            ] : null,
            'feedback_given' => \Illuminate\Support\Facades\DB::table('ticket_feedback')->where('ticket_id', $t->id)->exists(),
            'can_confirm' => $t->usesLifecycle() && $t->status === 'resolved',
            'can_reopen' => $t->usesLifecycle() && in_array($t->status, ['resolved', 'closed'], true) && $workflow->insideReopenWindow($t),
            // Soft target only: the time we aim to respond by. At-risk and breached flags never leave the staff side.
            'respond_by' => self::respondBy($t),
            'flagged_secret' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function staffSummary(Ticket $t): array
    {
        return self::common($t) + [
            'agency' => $t->relationLoaded('agency') ? ['id' => $t->agency_id, 'name' => $t->agency?->name] : null,
            'assigned_to' => $t->relationLoaded('assignee') && $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'assigned_team' => $t->assigned_team,
            'secret_flagged' => $t->secret_flagged_at !== null,
            'reopened_count' => $t->reopened_count,
            'scope_classification' => $t->scope_classification,
            'billing_status' => $t->billing_status,
            'sla' => $t->usesLifecycle() ? app(SlaService::class)->snapshot($t) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function staff(Ticket $t, User $viewer): array
    {
        return self::staffSummary($t) + [
            'description' => $t->description,
            'expected_result' => $t->expected_result,
            'details' => $t->details,
            'impact' => $t->impact,
            'requested_deadline' => $t->requested_deadline?->toDateString(),
            'issue_started_at' => $t->issue_started_at?->toISOString(),
            'submitted_by' => $t->relationLoaded('submitter') && $t->submitter ? ['id' => $t->submitter->id, 'name' => $t->submitter->name] : null,
            'reviewer' => $t->relationLoaded('reviewer') && $t->reviewer ? ['id' => $t->reviewer->id, 'name' => $t->reviewer->name] : null,
            'waiting' => $t->waiting_kind ? ['kind' => $t->waiting_kind, 'reason' => $t->waiting_reason, 'follow_up_date' => $t->follow_up_date?->toDateString()] : null,
            'first_response_at' => $t->first_response_at?->toISOString(),
            'resolution' => $t->resolution_summary ? ['summary' => $t->resolution_summary, 'category' => $t->resolution_category, 'root_cause' => $t->root_cause, 'resolved_at' => $t->resolved_at?->toISOString(), 'closed_at' => $t->closed_at?->toISOString()] : null,
            'allowed_transitions' => app(SupportWorkflow::class)->allowed($t, $viewer),
            'scope_proposal' => $t->scope_proposal ?? null,
            'feedback' => \Illuminate\Support\Facades\DB::table('ticket_feedback')->where('ticket_id', $t->id)->first(['rating', 'comment']),
            'entitlement' => app(TicketOperations::class)->entitlement($t),
            'converted_to' => $t->converted_to_type ? ['type' => $t->converted_to_type, 'id' => $t->converted_to_id] : null,
            'qa_required' => $t->usesLifecycle() && SupportWorkflow::qaRequired($t),
            'sla' => $t->usesLifecycle() ? app(SlaService::class)->snapshot($t) : null,
        ];
    }

    /** "We aim to respond by 3:30 PM": the response due time in the support timezone, until a first reply has been sent. */
    public static function respondBy(Ticket $t): ?string
    {
        if (! $t->usesLifecycle() || $t->first_response_at !== null) {
            return null;
        }
        $clock = ($t->relationLoaded('slaClocks') ? $t->slaClocks : $t->slaClocks()->get())->firstWhere('kind', 'response');

        return $clock && $clock->met_at === null ? $clock->due_at->setTimezone(config('support.timezone'))->format('D j M, g:i A') : null;
    }
}
