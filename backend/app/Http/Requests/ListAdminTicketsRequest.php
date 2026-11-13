<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListAdminTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'in:todo,in_progress,in_review,done,cancelled,blocked,waiting_on_client,changes_requested'],
            'agency_id' => ['sometimes', 'integer'],
            'employee_id' => ['sometimes', 'integer'],
            'waiting_on' => ['sometimes', 'string', 'in:client,employee,team'],
            'search' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
