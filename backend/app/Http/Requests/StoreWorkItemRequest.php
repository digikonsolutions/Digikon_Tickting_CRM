<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\BriefRules;
use App\Http\Requests\Concerns\ServiceDetailsRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkItemRequest extends FormRequest
{
    use BriefRules;
    use ServiceDetailsRules;

    public function authorize(): bool
    {
        return true;
    }

    /** Agency users never choose dates: with admin-only scheduling any start or due date that is sent is dropped. */
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
            ...$this->serviceDetailsRules('project'),
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'estimated_hours' => [$adminOnly ? 'nullable' : 'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.config('scheduling.max_project_hours', 180)],
            ...($adminOnly ? [] : [
                'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
                'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            ]),
            'assignment_id' => ['required', 'integer', 'exists:employee_assignments,id'],
            'agency_id' => ['prohibited'],
            'client_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
