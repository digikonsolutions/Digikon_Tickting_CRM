<?php

namespace App\Models;

use App\Support\GhlBrief;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkItemBrief extends Model
{
    protected $guarded = ['id', 'work_item_id'];

    protected function casts(): array
    {
        $json = array_fill_keys(array_values(GhlBrief::SECTION_COLUMNS), 'array');

        return $json + ['launch_date' => 'date:Y-m-d', 'ghl_synced_at' => 'datetime', 'completeness' => 'integer'];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }
}
