<?php

namespace App\Http\Requests;

use App\Support\TicketWorkOrder;
use Illuminate\Foundation\Http\FormRequest;

/** What the assigned employee delivers on a ticket. */
class UpdateTicketDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return TicketWorkOrder::deliveryRules();
    }
}
