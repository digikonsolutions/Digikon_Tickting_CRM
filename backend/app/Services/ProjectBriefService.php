<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemBrief;
use App\Support\GhlBrief;

/**
 * Reads and writes the detailed GoHighLevel brief of a project. Always called inside the request's own transaction
 * (project creation, brief edits), so a failure rolls the brief back with everything else.
 */
class ProjectBriefService
{
    /** Admins and the assigned employee may read internal notes; agency users never. */
    public function canSeeInternal(?User $viewer): bool
    {
        return $viewer !== null && in_array($viewer->role, [Role::Admin, Role::Employee], true);
    }

    private function row(WorkItem $item): WorkItemBrief
    {
        // work_item_id is guarded (never mass-assigned), so a new row gets it set explicitly.
        return WorkItemBrief::query()->where('work_item_id', $item->id)->first() ?? tap(new WorkItemBrief, fn (WorkItemBrief $new) => $new->work_item_id = $item->id);
    }

    /**
     * @param  array<string, mixed>  $brief  validated `ghl_brief` (sections as sent; agency users cannot send notes.internal)
     */
    public function save(WorkItem $item, array $brief, string $source = 'manual'): WorkItemBrief
    {
        $row = $this->row($item);
        $row->fill(GhlBrief::toColumns($brief));
        $row->source = $source;
        $row->ghl_synced_at = $source === 'ghl_prefill' ? now() : $row->ghl_synced_at;
        $row->completeness = GhlBrief::completeness(GhlBrief::assemble($row))['percent'];
        $row->save();

        return $row;
    }

    public function saveInternalNotes(WorkItem $item, ?string $notes): WorkItemBrief
    {
        $row = $this->row($item);
        $row->notes_internal = $notes;
        $row->save();

        return $row;
    }

    /**
     * What list endpoints show: enough for a card, never the sections themselves.
     *
     * @return array<string, mixed>|null
     */
    public function summary(WorkItem $item): ?array
    {
        $row = $item->ghlBrief;
        if ($row === null) {
            return null;
        }

        return [
            'client_name' => $row->client_name,
            'company' => $row->company,
            'categories' => $row->scope['categories'] ?? [],
            'launch_date' => $row->launch_date?->toDateString(),
            'priority' => $row->priority,
            'completeness' => $row->completeness,
            'source' => $row->source,
        ];
    }

    /**
     * The whole brief for a detail view.
     *
     * @return array<string, mixed>|null
     */
    public function full(WorkItem $item, ?User $viewer): ?array
    {
        $row = $item->ghlBrief;
        if ($row === null) {
            return null;
        }
        $sections = GhlBrief::assemble($row, $this->canSeeInternal($viewer));

        return $sections + [
            'completeness' => GhlBrief::completeness(GhlBrief::assemble($row)),
            'source' => $row->source,
            'ghl_synced_at' => $row->ghl_synced_at?->toISOString(),
            'updated_at' => $row->updated_at?->toISOString(),
        ];
    }
}
