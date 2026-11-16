<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\BriefRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing an existing project. Unlike creation, dates are not required to be in the future (an
 * active project's own start date may already be in the past) and nothing here changes `status`:
 * that stays driven by ticket completion or the dedicated cancel endpoint.
 */
class UpdateWorkItemRequest extends FormRequest
{
    use BriefRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->projectBriefRules(),
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'estimated_hours' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.config('scheduling.max_project_hours', 180)],
            'start_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'assignment_id' => ['sometimes', 'required', 'integer', 'exists:employee_assignments,id'],
            'agency_id' => ['prohibited'],
            'client_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
