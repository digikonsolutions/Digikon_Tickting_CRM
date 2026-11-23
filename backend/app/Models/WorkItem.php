<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class WorkItem extends Model
{
    /** Optional project brief fields (validated by BriefRules). */
    public const BRIEF_FIELDS = [
        'objective', 'deliverables', 'target_url', 'staging_url', 'repo_url', 'platform', 'tech_stack',
        'brand_assets_url', 'access_details', 'reference_links', 'acceptance_criteria', 'budget',
        'brief_priority', 'contact_name', 'contact_email',
    ];

    protected $fillable = [
        'agency_id',
        'client_id',
        'assignment_id',
        'employee_id',
        'title',
        'description',
        'estimated_hours',
        'start_date',
        'due_date',
        'status',
        'queue_position',
        'objective',
        'deliverables',
        'target_url',
        'staging_url',
        'repo_url',
        'platform',
        'tech_stack',
        'brand_assets_url',
        'access_details',
        'reference_links',
        'acceptance_criteria',
        'budget',
        'brief_priority',
        'contact_name',
        'contact_email',
    ];

    protected function casts(): array
    {
        return [
            'estimated_hours' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
            'timeline_proposed_at' => 'datetime',
            'timeline_confirmed_at' => 'datetime',
            'timeline_review_due_at' => 'datetime',
            'deliverables' => 'array',
            'reference_links' => 'array',
            // Access details are encrypted at rest with the application key.
            'access_details' => 'encrypted',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'assignment_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(WorkItemAllocation::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Latest owner or client proposal, used to explain a review request to administrators. */
    public function latestTimelineRequest(): HasOne
    {
        return $this->hasOne(TimelineEvent::class)->ofMany(['id' => 'max'], fn ($query) => $query
            ->where('type', 'proposed')
            ->whereIn('actor_role', ['agency_owner', 'agency_client'])
        );
    }

    /**
     * First ticket (by position) that is neither done nor cancelled.
     */
    public function nextTicket(): HasOne
    {
        return $this->hasOne(Ticket::class)->ofMany(
            ['position' => 'min', 'id' => 'min'],
            fn ($query) => $query->whereNotIn('status', ['done', 'cancelled'])
        );
    }

    /**
     * @return array<string, \Closure>
     */
    private static function ticketProgressCounts(): array
    {
        return [
            'tickets as tickets_total' => fn ($query) => $query->where('status', '!=', 'cancelled'),
            'tickets as tickets_done' => fn ($query) => $query->where('status', 'done'),
            'tickets as tickets_open' => fn ($query) => $query->whereNotIn('status', ['done', 'cancelled']),
        ];
    }

    /**
     * Preload ticket progress counts and the next ticket for a list of projects.
     */
    public function scopeWithTicketProgress(Builder $query): Builder
    {
        return $query->withCount(self::ticketProgressCounts())->with(['nextTicket.agency']);
    }

    /**
     * Load (or refresh) ticket progress on a single project.
     */
    public function loadTicketProgress(): static
    {
        $this->unsetRelation('nextTicket');

        return $this->loadCount(self::ticketProgressCounts())->load(['nextTicket.agency']);
    }

    public function hasTicketProgress(): bool
    {
        return array_key_exists('tickets_total', $this->attributes) && $this->relationLoaded('nextTicket');
    }
}
