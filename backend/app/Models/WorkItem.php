<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkItem extends Model
{
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
    ];

    protected function casts(): array
    {
        return [
            'estimated_hours' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
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

    public function allocations(): HasMany
    {
        return $this->hasMany(WorkItemAllocation::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
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
        return $query->withCount(self::ticketProgressCounts())->with('nextTicket');
    }

    /**
     * Load (or refresh) ticket progress on a single project.
     */
    public function loadTicketProgress(): static
    {
        $this->unsetRelation('nextTicket');

        return $this->loadCount(self::ticketProgressCounts())->load('nextTicket');
    }

    public function hasTicketProgress(): bool
    {
        return array_key_exists('tickets_total', $this->attributes) && $this->relationLoaded('nextTicket');
    }
}
