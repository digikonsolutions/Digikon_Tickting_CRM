<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\BriefRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    use BriefRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $project = $this->route('workItem');

        return [
            ...$this->ticketBriefRules($project?->id),
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string'],
            'estimated_hours' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'work_item_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'status' => ['prohibited'],
            'position' => ['prohibited'],
            'start_date' => ['prohibited'],
            'due_date' => ['prohibited'],
        ];
    }
}
