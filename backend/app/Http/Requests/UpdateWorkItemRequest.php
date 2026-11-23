<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\BriefRules;
use App\Http\Requests\Concerns\ServiceDetailsRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing an existing project. Unlike creation, dates are not required to be in the future (an
 * active project's own start date may already be in the past) and nothing here changes `status`:
 * that stays driven by ticket completion or the dedicated cancel endpoint.
 */
class UpdateWorkItemRequest extends FormRequest
{
    use BriefRules;
    use ServiceDetailsRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (config('scheduling.admin_only_scheduling', true)) {
            $this->replace($this->except(['start_date', 'due_date']));
        }
    }

    public function rules(): array
    {
        $adminOnly = config('scheduling.admin_only_scheduling', true);

        return [
            ...$this->projectBriefRules(),
            ...$this->serviceDetailsRules('project', $this->route('workItem')?->service_type, creating: false),
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'estimated_hours' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.config('scheduling.max_project_hours', 180)],
            ...($adminOnly ? [] : [
                'start_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
                'due_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            ]),
            'assignment_id' => ['sometimes', 'required', 'integer', 'exists:employee_assignments,id'],
            'agency_id' => ['prohibited'],
            'client_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
