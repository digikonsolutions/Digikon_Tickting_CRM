<?php

namespace App\Http\Requests;

use App\Support\TicketWorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The blocked flag and its reason, set by the assigned employee (information only; it does not change the status). */
class UpdateTicketBlockedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'blocked' => ['required', 'boolean'],
            'reason' => ['required_if:blocked,true', 'nullable', 'string', 'max:1000'],
            'waiting_on' => ['nullable', Rule::in(TicketWorkOrder::WAITING_ON)],
        ];
    }
}
