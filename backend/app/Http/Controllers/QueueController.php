<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\ReorderQueueRequest;
use App\Jobs\NotifyQueueChange;
use App\Models\EmployeeAssignment;
use App\Models\User;
use App\Services\EmployeeQueuePlanner;
use App\Services\QueuePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An employee's queue of open projects. Owners view and reorder every queue of their agency; clients view them (and
 * append projects through the project form); the employee reads their own; admins read any. Always scoped by agency;
 * anything outside the caller's scope is a 404.
 */
class QueueController extends Controller
{
    public function show(Request $request, EmployeeAssignment $assignment, QueuePresenter $presenter): JsonResponse
    {
        $employee = $this->employeeFor($request->user(), $assignment);

        return response()->json($presenter->present($employee));
    }

    public function preview(ReorderQueueRequest $request, EmployeeAssignment $assignment, EmployeeQueuePlanner $planner): JsonResponse
    {
        $employee = $this->employeeFor($request->user(), $assignment, ownerOnly: true);

        return response()->json($planner->preview($employee, $request->validated('ordered_work_item_ids')));
    }

    public function reorder(ReorderQueueRequest $request, EmployeeAssignment $assignment, EmployeeQueuePlanner $planner, QueuePresenter $presenter): JsonResponse
    {
        $user = $request->user();
        $employee = $this->employeeFor($user, $assignment, ownerOnly: true);

        $result = $planner->reorder($employee, $request->validated('ordered_work_item_ids'), $user);
        NotifyQueueChange::dispatch($employee->id, 'reordered', $user->id);

        return response()->json([
            ...$presenter->present($employee),
            'rescheduled_work_item_ids' => $result['changed'],
        ]);
    }

    public function mine(Request $request, QueuePresenter $presenter): JsonResponse
    {
        $user = $request->user();
        if ($user->agency_id !== null) {
            abort_unless($user->agency?->status === 'active', 403, 'An active agency is required to access employee work.');
        }

        return response()->json($presenter->present($user));
    }

    public function admin(User $employee, QueuePresenter $presenter): JsonResponse
    {
        abort_unless($employee->role === Role::Employee, 404);

        return response()->json($presenter->present($employee));
    }

    private function employeeFor(User $user, EmployeeAssignment $assignment, bool $ownerOnly = false): User
    {
        abort_unless($assignment->agency_id === $user->agency_id && $assignment->status === 'active', 404);
        abort_unless($user->role === Role::AgencyOwner || (! $ownerOnly && $user->role === Role::AgencyClient), 404);

        $employee = $assignment->employee;
        abort_unless($employee !== null && $employee->is_active, 404);

        return $employee;
    }
}
