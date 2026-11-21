<?php

namespace App\Http\Requests;

use App\Services\AdminDashboardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Legacy filter for the recent-projects list.
            'status' => ['sometimes', 'in:scheduled,in_progress,completed,cancelled'],
            // Any date inside the week to report on; defaults to the current week.
            'week' => ['sometimes', 'date_format:Y-m-d'],
            // Narrows only the "employee capacity" list.
            'employee_filter' => ['sometimes', Rule::in(AdminDashboardService::EMPLOYEE_FILTERS)],
        ];
    }
}
