<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    protected $fillable = [
        'agency_id',
        'work_item_id',
        'created_by',
        'number',
        'title',
        'description',
        'estimated_hours',
        'priority',
        'status',
        'position',
        'start_date',
        'due_date',
        'steps_to_reproduce',
        'expected_result',
        'actual_result',
        'affected_url',
        'environment',
        'severity',
        'due_preference',
        'related_ticket_ids',
        'service_type',
        'project_service_id',
        'details',
        'kind', 'ref', 'type', 'end_client_id', 'related_project_id', 'service', 'submitted_by_id', 'assigned_to_id', 'assigned_team', 'reviewer_id',
        'requested_deadline', 'impact', 'issue_started_at', 'scope_classification', 'billing_status', 'waiting_reason', 'waiting_kind', 'follow_up_date',
        'resolution_summary', 'resolution_category', 'root_cause', 'secret_flagged_at',
    ];

    protected $appends = [
        'key',
    ];

    protected static function booted(): void
    {
        // Whatever moves a ticket to "in progress" (workflow, status endpoint, tests) stamps when work first began.
        static::saving(function (Ticket $ticket): void {
            if ($ticket->status === 'in_progress' && $ticket->started_at === null) {
                $ticket->started_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'agency_id' => 'integer',
            'number' => 'integer',
            'estimated_hours' => 'decimal:2',
            'position' => 'integer',
            'start_date' => 'date',
            'due_date' => 'date',
            'timeline_proposed_at' => 'datetime',
            'timeline_confirmed_at' => 'datetime',
            'related_ticket_ids' => 'array',
            'details' => 'array',
            'impact' => 'array',
            'requested_deadline' => 'date',
            'follow_up_date' => 'date',
            'issue_started_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'scope_approved_at' => 'datetime',
            'secret_flagged_at' => 'datetime',
            'waiting_since' => 'datetime',
            'scope_proposal' => 'array',
            'reopened_count' => 'integer',
        ];
    }

    protected function key(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $prefix = $this->relationLoaded('agency')
                    ? $this->agency?->ticket_prefix
                    : ($this->relationLoaded('workItem') && $this->workItem?->relationLoaded('agency')
                        ? $this->workItem->agency?->ticket_prefix
                        : ($this->agency_id ? Agency::query()->where('id', $this->agency_id)->value('ticket_prefix') : null));

                return ($prefix && $this->number) ? "{$prefix}-{$this->number}" : null;
            }
        );
    }

    public function projectService(): BelongsTo
    {
        return $this->belongsTo(ProjectService::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TicketAllocation::class);
    }

    /** The open request for more hours, if the employee has asked. */
    public function pendingHoursRequest(): HasOne
    {
        return $this->hasOne(HoursRequest::class)->where('status', 'pending')->latestOfMany();
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    /**
     * The latest status change; eager-loadable so list payloads avoid one query per ticket.
     */
    public function latestStatusEvent(): HasOne
    {
        return $this->hasOne(TicketEvent::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('type', 'status_changed')
        );
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    /** The work order (what is specific to this ticket); absent for tickets created without one. */
    public function detail(): HasOne
    {
        return $this->hasOne(TicketDetail::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TicketChecklistItem::class)->orderBy('position');
    }

    /** Tickets made through the support module use the lifecycle statuses; older hour-slice tickets have no `type`. */
    public function usesLifecycle(): bool
    {
        return $this->type !== null;
    }

    public function endClient(): BelongsTo
    {
        return $this->belongsTo(EndClient::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function relatedProject(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'related_project_id');
    }

    public function slaClocks(): HasMany
    {
        return $this->hasMany(TicketSlaClock::class);
    }
}
