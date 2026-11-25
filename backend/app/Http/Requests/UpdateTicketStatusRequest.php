<?php

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Services\TicketDetailsService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Sending a ticket that has a work order for review needs what was done and at least one link (see
     * TicketDetailsService::assertDeliverable). Tickets without a work order are never held back.
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $ticket = $this->route('ticket');
            if ($validator->errors()->isNotEmpty() || $this->input('status') !== 'in_review' || ! $ticket instanceof Ticket) {
                return;
            }
            try {
                app(TicketDetailsService::class)->assertDeliverable($ticket);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            }
        }];
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['in_progress', 'in_review', 'done'])],
        ];
    }
}
