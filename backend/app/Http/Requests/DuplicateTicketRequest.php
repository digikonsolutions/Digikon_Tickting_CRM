<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DuplicateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:160'],
            // The copy needs hours of its own; the planner and the project's hour cap apply as for any new ticket.
            'estimated_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01'],
        ];
    }
}
