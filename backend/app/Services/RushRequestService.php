<?php

namespace App\Services;

use App\Enums\Role;
use App\Events\RushRequestEvent;
use App\Models\AgencyCharge;
use App\Models\EmployeeAssignment;
use App\Models\RushRequest;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Urgent ("rush") requests an agency can pay for. Prices are quoted by an admin and accepted by the agency owner;
 * accepting writes a charge row (a ledger entry, nothing is collected) and does the work in one transaction:
 *
 * - priority_insert: a new project goes into the held employee's queue right behind work already in progress.
 * - extra_employee: a temporary assignment of a team member the admin picked, with the requested project.
 */
class RushRequestService
{
    public function __construct(private EmployeeQueuePlanner $planner, private WorkItemScheduler $scheduler) {}

    /** @param  array<string, mixed>  $data */
    public function submit(User $user, array $data): RushRequest
    {
        $assignmentId = null;
        if ($data['type'] === 'priority_insert') {
            $assignment = EmployeeAssignment::query()
                ->whereKey($data['assignment_id'] ?? 0)
                ->where('agency_id', $user->agency_id)
                ->where('status', 'active')
                ->first();
            if ($assignment === null || ! $assignment->usableBy($user)) {
                throw ValidationException::withMessages(['assignment_id' => 'Choose a team member from your team.']);
            }
            $assignmentId = $assignment->id;
        }

        $request = RushRequest::create([
            'agency_id' => $user->agency_id,
            'requested_by' => $user->id,
            'type' => $data['type'],
            'assignment_id' => $assignmentId,
            'title' => $data['title'],
            'description' => $data['description'],
            'skills_notes' => $data['type'] === 'extra_employee' ? ($data['skills_notes'] ?? null) : null,
            'estimated_hours' => $data['estimated_hours'],
            'needed_by' => $data['needed_by'],
            'reason' => $data['reason'] ?? null,
            'service_type' => $data['service_type'] ?? null,
            'details' => ! empty($data['service_type']) ? ($data['details'] ?? null) : null,
            'status' => 'pending',
        ]);
        $this->announce($request, 'submitted', $user);

        return $request;
    }

    /**
     * Admin sets the price. For extra_employee the admin also picks who; that choice is never shown to the agency.
     *
     * @param  array{amount_minor: int, valid_hours?: int|null, note?: string|null, employee_id?: int|null}  $data
     */
    public function quote(RushRequest $request, User $admin, array $data): RushRequest
    {
        $quoted = DB::transaction(function () use ($request, $admin, $data): RushRequest {
            $locked = RushRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($locked->status, RushRequest::OPEN, true)) {
                throw ValidationException::withMessages(['status' => 'Only open requests can be quoted.']);
            }

            $employeeId = null;
            if ($locked->type === 'extra_employee') {
                $employee = $this->availableEmployee((int) ($data['employee_id'] ?? 0), $locked->agency_id);
                if ($employee === null) {
                    throw ValidationException::withMessages(['employee_id' => 'Choose an available employee for this agency.']);
                }
                $today = CarbonImmutable::now(config('app.timezone'));
                $available = $this->scheduler->availableUnits($employee, $today, CarbonImmutable::parse($locked->needed_by->toDateString(), config('app.timezone')));
                if ($available < (int) round((float) $locked->estimated_hours * 100)) {
                    throw ValidationException::withMessages(['employee_id' => 'This employee does not have enough free hours before the date it is needed.']);
                }
                $employeeId = $employee->id;
            } elseif ($locked->assignment?->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'The team member this request was for is no longer on the team.']);
            }

            $hours = (int) ($data['valid_hours'] ?? config('billing.default_quote_validity_hours', 48));
            $locked->forceFill([
                'status' => 'quoted',
                'quoted_amount_minor' => (int) $data['amount_minor'],
                'currency' => config('billing.currency'),
                'quoted_by' => $admin->id,
                'quote_expires_at' => now()->addHours($hours),
                'quote_note' => $data['note'] ?? null,
                'proposed_employee_id' => $employeeId,
            ])->save();

            return $locked;
        });
        $this->announce($quoted, 'quoted', $admin);

        return $quoted;
    }

    public function reject(RushRequest $request, User $admin, string $reason): RushRequest
    {
        $rejected = DB::transaction(function () use ($request, $reason): RushRequest {
            $locked = RushRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($locked->status, RushRequest::OPEN, true)) {
                throw ValidationException::withMessages(['status' => 'Only open requests can be rejected.']);
            }
            $locked->forceFill(['status' => 'rejected', 'rejection_reason' => $reason, 'responded_at' => now()])->save();

            return $locked;
        });
        $this->announce($rejected, 'rejected', $admin);

        return $rejected;
    }

    /** The agency withdraws a request (the requester, or the owner), or the owner declines a quote. */
    public function cancel(RushRequest $request, User $user, bool $declined = false): RushRequest
    {
        $cancelled = DB::transaction(function () use ($request, $declined): RushRequest {
            $locked = RushRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($locked->status, RushRequest::OPEN, true) || ($declined && $locked->status !== 'quoted')) {
                throw ValidationException::withMessages(['status' => $declined ? 'There is no quote to decline.' : 'Only open requests can be cancelled.']);
            }
            $locked->forceFill(['status' => 'cancelled', 'responded_at' => now()])->save();

            return $locked;
        });
        $this->announce($cancelled, $declined ? 'declined' : 'cancelled', $user);

        return $cancelled;
    }

    /** Marks quotes past their expiry as expired. Returns how many. */
    public function expireQuotes(): int
    {
        $expired = RushRequest::query()->where('status', 'quoted')->where('quote_expires_at', '<=', now())->pluck('id');
        $count = 0;
        foreach ($expired as $id) {
            $done = DB::transaction(function () use ($id): ?RushRequest {
                $locked = RushRequest::query()->lockForUpdate()->find($id);
                if ($locked === null || $locked->status !== 'quoted' || $locked->quote_expires_at->isFuture()) {
                    return null;
                }
                $locked->forceFill(['status' => 'expired', 'responded_at' => now()])->save();

                return $locked;
            });
            if ($done !== null) {
                $count++;
                $this->announce($done, 'expired');
            }
        }

        return $count;
    }

    /**
     * What a priority insert would do to the queue: where the new project lands and which others slip.
     * A dry run; nothing is saved.
     *
     * @return array{fits: bool, new_project: array<string, mixed>, affected: list<array<string, mixed>>, requires_confirmation: bool}
     */
    public function impact(RushRequest $request): array
    {
        $assignment = $request->assignment;
        abort_unless($request->type === 'priority_insert' && $assignment?->status === 'active' && $assignment->employee !== null, 404);

        DB::beginTransaction();
        try {
            $employee = $assignment->employee;
            [$item, $preview] = $this->stageInsert($request, $employee);

            return $this->summariseImpact($item, $preview);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * The owner accepts the quote: charge row, work, and request status change in one transaction.
     * A priority insert that would push other projects past their due dates needs $confirmImpact.
     */
    public function accept(RushRequest $request, User $owner, bool $confirmImpact): RushRequest
    {
        $fresh = RushRequest::query()->find($request->id);
        if ($fresh?->status === 'quoted' && $fresh->quote_expires_at !== null && $fresh->quote_expires_at->lte(now())) {
            $this->expireQuotes();
            throw ValidationException::withMessages(['status' => 'This quote has expired. Ask for a new one.']);
        }

        $item = null;
        try {
            $accepted = DB::transaction(function () use ($request, $owner, $confirmImpact, &$item): RushRequest {
                $locked = RushRequest::query()->lockForUpdate()->findOrFail($request->id);
                if ($locked->status !== 'quoted' || $locked->quote_expires_at === null || $locked->quote_expires_at->lte(now())) {
                    throw ValidationException::withMessages(['status' => 'This quote can no longer be accepted.']);
                }

                $item = $locked->type === 'priority_insert'
                    ? $this->fulfilPriorityInsert($locked, $owner, $confirmImpact)
                    : $this->fulfilExtraEmployee($locked, $owner);

                // Ledger entry only. Extension point: a payment gateway / invoice provider picks up `unbilled`
                // charges from here; nothing in this app collects money.
                AgencyCharge::create([
                    'agency_id' => $locked->agency_id,
                    'rush_request_id' => $locked->id,
                    'description' => ($locked->type === 'priority_insert' ? 'Urgent request: ' : 'Additional team member: ').$locked->title,
                    'amount_minor' => $locked->quoted_amount_minor,
                    'currency' => $locked->currency,
                    'status' => 'unbilled',
                ]);

                $locked->forceFill([
                    'status' => 'fulfilled',
                    'responded_at' => now(),
                    'fulfilled_work_item_id' => $item->id,
                ])->save();

                return $locked;
            });
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['status' => 'This request could not be completed. Please try again.']);
        }

        $this->announce($accepted, 'accepted', $owner);
        $this->announce($accepted, 'fulfilled', $owner);

        // A new project follows the same timeline review as any project the agency creates.
        $project = WorkItem::query()->with(['employee', 'agency'])->findOrFail($item->id);
        if (TimelineReview::required()) {
            app(TimelineReview::class)->propose($project, $owner);
        } else {
            app(WorkflowNotificationService::class)->projectCreated($project, $owner);
        }

        return $accepted;
    }

    private function fulfilPriorityInsert(RushRequest $request, User $owner, bool $confirmImpact): WorkItem
    {
        $assignment = EmployeeAssignment::query()->lockForUpdate()->find($request->assignment_id);
        if ($assignment === null || $assignment->status !== 'active' || $assignment->agency_id !== $request->agency_id) {
            throw ValidationException::withMessages(['status' => 'This team member is no longer on your team. Cancel the request.']);
        }
        $employee = User::query()->lockForUpdate()->findOrFail($assignment->employee_id);
        if (! $employee->is_active || $employee->role !== Role::Employee) {
            throw ValidationException::withMessages(['status' => 'This team member is no longer available. Cancel the request.']);
        }

        [$item, $preview] = $this->stageInsert($request, $employee);
        $impact = $this->summariseImpact($item, $preview);

        if (! $impact['fits']) {
            throw ValidationException::withMessages(['needed_by' => 'This work cannot be finished by the date it is needed, even at the front of the queue.']);
        }
        if ($impact['requires_confirmation'] && ! $confirmImpact) {
            throw RushImpactNotConfirmed::forImpact($impact);
        }

        try {
            $this->planner->replan($employee, null, $owner, 'rush_inserted', [
                'work_item_id' => $item->id,
                'rush_request_id' => $request->id,
                'pushed_past_due' => array_column($impact['affected'], 'work_item_id'),
            ], allowSlips: true);
        } catch (QueueConflict) {
            throw ValidationException::withMessages(['needed_by' => 'This work cannot be finished by the date it is needed, even at the front of the queue.']);
        }

        return $item->refresh();
    }

    private function fulfilExtraEmployee(RushRequest $request, User $owner): WorkItem
    {
        $unavailable = ValidationException::withMessages(['status' => 'This request can no longer be fulfilled.']);
        $employee = $request->proposed_employee_id === null ? null : $this->availableEmployee($request->proposed_employee_id, $request->agency_id, lock: true);
        if ($employee === null) {
            throw $unavailable;
        }

        // The existing exclusivity rules apply: active_employee_id is unique, so one team member is held by one agency.
        $assignment = EmployeeAssignment::create([
            'holder_id' => $owner->id,
            'agency_id' => $request->agency_id,
            'employee_id' => $employee->id,
            'status' => 'active',
            'source' => 'rush',
            'active_employee_id' => $employee->id,
            'started_at' => now(config('app.timezone')),
            'expires_at' => CarbonImmutable::parse($request->needed_by->toDateString(), config('app.timezone'))->endOfDay(),
        ]);
        $request->forceFill(['temp_assignment_id' => $assignment->id])->save();

        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $item = WorkItem::create([
            'agency_id' => $request->agency_id,
            'client_id' => $request->requested_by,
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'title' => $request->title,
            'description' => $request->description,
            'estimated_hours' => $request->estimated_hours,
            'start_date' => $today,
            'due_date' => $request->needed_by->toDateString(),
            'status' => 'scheduled',
            'queue_position' => $this->planner->nextPosition($employee),
            'brief_priority' => 'urgent',
            'service_type' => $request->service_type,
            'details' => $request->details,
        ]);

        try {
            $this->planner->replan($employee, null, $owner, 'rush_inserted', ['work_item_id' => $item->id, 'rush_request_id' => $request->id]);
        } catch (QueueConflict) {
            throw $unavailable;
        }

        return $item->refresh();
    }

    /**
     * Creates the rush project right behind the in-progress work (inside the caller's transaction) and previews the
     * resulting queue. The caller keeps or rolls back what was created.
     *
     * @return array{0: WorkItem, 1: array<string, mixed>}
     */
    private function stageInsert(RushRequest $request, User $employee): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $position = $this->planner->makeRoomAfterActive($employee);
        $item = WorkItem::create([
            'agency_id' => $request->agency_id,
            'client_id' => $request->requested_by,
            'assignment_id' => $request->assignment_id,
            'employee_id' => $employee->id,
            'title' => $request->title,
            'description' => $request->description,
            'estimated_hours' => $request->estimated_hours,
            'start_date' => $today,
            'due_date' => $request->needed_by->toDateString(),
            'status' => 'scheduled',
            'queue_position' => $position,
            'brief_priority' => 'urgent',
            'service_type' => $request->service_type,
            'details' => $request->details,
        ]);

        $order = $this->planner->openItems($employee)->pluck('id')->all();

        return [$item, $this->planner->preview($employee, $order, $item->id)];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array{fits: bool, new_project: array<string, mixed>, affected: list<array<string, mixed>>, requires_confirmation: bool}
     */
    private function summariseImpact(WorkItem $item, array $preview): array
    {
        $rows = collect($preview['items'])->keyBy('work_item_id');
        $new = $rows[$item->id];
        $affected = collect($preview['conflicts'])
            ->reject(fn (array $c): bool => $c['work_item_id'] === $item->id)
            ->map(fn (array $c): array => [
                'work_item_id' => $c['work_item_id'],
                'title' => $c['title'],
                'due_date' => $c['due_date'],
                'planned_end' => $rows[$c['work_item_id']]['planned_end'],
                'slips_by_days' => $c['slips_by_days'],
            ])->values()->all();

        return [
            'fits' => $new['fits'],
            'new_project' => [
                'planned_start' => $new['planned_start'],
                'planned_end' => $new['planned_end'],
                'due_date' => $new['due_date'],
                'slips_by_days' => $new['slips_by_days'],
            ],
            'affected' => $affected,
            'requires_confirmation' => $affected !== [],
        ];
    }

    private function availableEmployee(int $employeeId, int $agencyId, bool $lock = false): ?User
    {
        $query = User::query()->whereKey($employeeId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $employee = $query->first();

        if (
            $employee === null
            || ! $employee->is_active
            || $employee->role !== Role::Employee
            || ! in_array($employee->agency_id, [null, $agencyId], true)
            || EmployeeAssignment::query()->where('active_employee_id', $employee->id)->exists()
        ) {
            return null;
        }

        return $employee;
    }

    private function announce(RushRequest $request, string $name, ?User $actor = null): void
    {
        DB::afterCommit(fn () => RushRequestEvent::dispatch($request->id, $name, $actor?->id));
    }
}
