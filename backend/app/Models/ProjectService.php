<?php

namespace App\Models;

use App\Support\ServiceCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One GoHighLevel service a project asks for, with the answers to that service's questions. */
class ProjectService extends Model
{
    protected $fillable = ['work_item_id', 'service_key', 'details', 'estimated_hours', 'position'];

    protected function casts(): array
    {
        return ['details' => 'array', 'estimated_hours' => 'decimal:2', 'position' => 'integer'];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function label(): string
    {
        return ServiceCatalog::types()[$this->service_key]['label'] ?? $this->service_key;
    }
}