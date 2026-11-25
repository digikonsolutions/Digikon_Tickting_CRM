<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeFeedback;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Collection;

/** Rules and presentation for employee feedback: who may be reviewed, the averages, and what each viewer is shown. */
class EmployeeFeedbackService
{
    /** The questions and options the form is built from. */
    public function form(): array
    {
        $options = fn (array $items): array => collect($items)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all();

        return [
            'categories' => $options(config('feedback.categories')),
            'would_again' => $options(config('feedback.would_again')),
            'highlights' => $options(config('feedback.highlights')),
            'concerns' => $options(config('feedback.concerns')),
            'edit_days' => (int) config('feedback.edit_days'),
        ];
    }

    /**
     * The assignment this feedback belongs to: the employee must be, or have been, held by the owner's agency. When a
     * project is named it must be one of that agency's projects done by this employee, and it decides the assignment.
     *
     * @return array{0: EmployeeAssignment, 1: WorkItem|null}
     */
    public function resolve(User $owner, int $employeeId, ?int $workItemId): array
    {
        $assignments = EmployeeAssignment::query()->where('agency_id', $owner->agency_id)->where('employee_id', $employeeId)->orderByDesc('id')->get();
        abort_if($assignments->isEmpty(), 404);

        if ($workItemId === null) {
            return [$assignments->first(), null];
        }
        $project = WorkItem::query()->where('agency_id', $owner->agency_id)->where('employee_id', $employeeId)->find($workItemId);
        abort_if($project === null, 422, 'That project was not done by this employee.');
        $assignment = $assignments->firstWhere('id', $project->assignment_id);
        abort_if($assignment === null, 422, 'That project was not done by this employee.');

        return [$assignment, $project];
    }

    /**
     * Averages over published feedback.
     *
     * @param  Collection<int, EmployeeFeedback>  $rows
     * @return array<string, mixed>
     */
    public function summary(Collection $rows): array
    {
        $count = $rows->count();
        $categories = [];
        foreach (config('feedback.categories') as $key => $label) {
            $values = $rows->map(fn (EmployeeFeedback $f) => $f->ratings[$key] ?? null)->filter()->values();
            $categories[] = ['key' => $key, 'label' => $label, 'average' => $values->isEmpty() ? null : round($values->avg(), 1), 'count' => $values->count()];
        }
        $again = $rows->countBy('would_again');

        return [
            'count' => $count,
            'average' => $count ? round($rows->avg('rating'), 1) : null,
            'distribution' => collect(range(5, 1))->map(fn (int $stars): array => ['stars' => $stars, 'count' => $rows->where('rating', $stars)->count()])->values()->all(),
            'would_again' => ['yes' => (int) ($again['yes'] ?? 0), 'maybe' => (int) ($again['maybe'] ?? 0), 'no' => (int) ($again['no'] ?? 0)],
            'would_again_percent' => $count ? (int) round((($again['yes'] ?? 0) / $count) * 100) : null,
            'categories' => $categories,
            'last_at' => $rows->max('created_at')?->toISOString(),
        ];
    }

    /**
     * One feedback as this viewer may read it. The employee never gets the private note or the writer; agency clients
     * never get the private note; admins see all.
     *
     * @return array<string, mixed>
     */
    public function present(EmployeeFeedback $feedback, User $viewer): array
    {
        $admin = $viewer->role === Role::Admin;
        $employee = $viewer->role === Role::Employee;
        $label = fn (array $keys, string $group): array => collect($keys ?? [])->map(fn (string $key): array => ['key' => $key, 'label' => config("feedback.$group.$key", $key)])->values()->all();
        $feedback->loadMissing(['agency', 'author:id,name', 'workItem:id,title', 'reply']);

        $row = [
            'id' => $feedback->id,
            'employee_id' => $feedback->employee_id,
            'agency_name' => $admin ? $feedback->agency?->name : ($feedback->agency ? AgencyBrandingPresenter::displayName($feedback->agency) : null),
            'rating' => $feedback->rating,
            'ratings' => $feedback->ratings ?? [],
            'would_again' => $feedback->would_again,
            'went_well' => $feedback->went_well,
            'to_improve' => $feedback->to_improve,
            'highlights' => $label($feedback->highlights ?? [], 'highlights'),
            'concerns' => $label($feedback->concerns ?? [], 'concerns'),
            'project' => $feedback->workItem ? ['id' => $feedback->workItem->id, 'title' => $feedback->workItem->title] : null,
            'created_at' => $feedback->created_at?->toISOString(),
            'editable' => $viewer->role === Role::AgencyOwner && $feedback->agency_id === $viewer->agency_id && $feedback->isEditable(),
            'editable_until' => $viewer->role === Role::AgencyOwner ? $feedback->editable_until?->toISOString() : null,
            'reply' => $feedback->reply ? ['body' => $feedback->reply->body, 'created_at' => $feedback->reply->created_at?->toISOString()] : null,
            'status' => $feedback->status,
        ];
        if (! $employee) {
            $row['author'] = $feedback->author?->name;
        }
        if ($admin || $viewer->role === Role::AgencyOwner) {
            $row['private_note'] = $feedback->private_note;
        }
        if ($admin) {
            $row['removed_reason'] = $feedback->removed_reason;
            $row['agency_id'] = $feedback->agency_id;
        }

        return $row;
    }
}
