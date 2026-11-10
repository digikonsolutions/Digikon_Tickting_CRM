<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'estimated_hours' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.config('scheduling.max_project_hours', 180)],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'assignment_id' => ['required', 'integer', 'exists:employee_assignments,id'],
            'agency_id' => ['prohibited'],
            'client_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
