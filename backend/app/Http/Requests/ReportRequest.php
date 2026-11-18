<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Services\Reports\Report;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Filters shared by every report. The audience (admin or agency) comes from the signed-in user, never
 * from the request, and `agency_id` / `client_id` are rejected for roles that may not use them.
 */
class ReportRequest extends FormRequest
{
    private ?Report $resolved = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    private function isAdmin(): bool
    {
        return $this->user()?->role === Role::Admin;
    }

    /** The report named in the URL; 404 when it does not exist for this audience. */
    public function report(): Report
    {
        return $this->resolved ??= ReportRegistry::resolve((string) $this->route('type'), $this->isAdmin())
            ?? abort(404, 'Unknown report.');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $report = $this->report();
        $role = $this->user()->role;

        return [
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['sometimes', 'nullable', 'in:'.implode(',', $report->statuses() ?: ['__none__'])],
            'agency_id' => $this->isAdmin() ? ['sometimes', 'nullable', 'integer', 'min:1'] : ['prohibited'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'client_id' => $role === Role::AgencyClient ? ['prohibited'] : ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'in:'.implode(',', $report->sortable() ?: ['__none__'])],
            'direction' => ['sometimes', 'nullable', 'in:asc,desc'],
            'format' => ['sometimes', 'in:json,csv'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'agency_id.prohibited' => 'Only administrators can filter by agency.',
            'client_id.prohibited' => 'You can only see your own projects.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
            'status.in' => 'That status is not available for this report.',
            'sort.in' => 'That column cannot be sorted.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['from', 'to'])) {
                    return;
                }
                $filters = $this->filters(true);
                if ($filters->from->diffInDays($filters->to) >= ReportFilters::MAX_RANGE_DAYS) {
                    $validator->errors()->add('to', 'The date range may not exceed one year.');
                }
            },
        ];
    }

    public function filters(bool $raw = false): ReportFilters
    {
        // validated() is not available while validation is still running (the range check above).
        $data = $raw ? $this->only(['from', 'to']) : $this->validated();

        return ReportFilters::fromArray($data);
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }
}
