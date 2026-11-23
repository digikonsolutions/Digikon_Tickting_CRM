<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ordered_work_item_ids' => ['required', 'array', 'max:200'],
            'ordered_work_item_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
