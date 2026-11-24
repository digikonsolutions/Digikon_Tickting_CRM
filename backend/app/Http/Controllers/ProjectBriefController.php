<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsProjectAccess;
use App\Http\Requests\UpdateInternalNotesRequest;
use App\Http\Requests\UpdateProjectBriefRequest;
use App\Models\WorkItem;
use App\Services\ProjectBriefService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** The detailed GoHighLevel brief of a project. Agency users write it; admins and the assigned employee only read it. */
class ProjectBriefController extends Controller
{
    use GuardsProjectAccess;

    /**
     * Whole sections are replaced (send a section as it should now be); sections that are not sent stay as they are.
     * Same guards as editing a ticket: the caller's own agency, an open project, an active assignment.
     */
    public function update(UpdateProjectBriefRequest $request, WorkItem $workItem, ProjectBriefService $briefs): JsonResponse
    {
        $user = $request->user();
        $this->authorizeProject($user, $workItem, requireOpen: true, requireActiveAssignment: true);

        DB::transaction(function () use ($workItem, $request, $briefs): void {
            $this->lockActiveProjectAssignment($workItem);
            $locked = WorkItem::query()->lockForUpdate()->findOrFail($workItem->id);
            $this->ensureOpenProject($locked);
            $briefs->save($locked, $request->validated('ghl_brief'));
        });

        return response()->json(['ghl_brief' => $briefs->full($workItem->fresh(), $user)]);
    }

    /** Internal notes: administrators only. Agency users never see them. */
    public function updateInternalNotes(UpdateInternalNotesRequest $request, WorkItem $workItem, ProjectBriefService $briefs): JsonResponse
    {
        $briefs->saveInternalNotes($workItem, $request->validated('notes'));

        return response()->json(['ghl_brief' => $briefs->full($workItem->fresh(), $request->user())]);
    }
}
