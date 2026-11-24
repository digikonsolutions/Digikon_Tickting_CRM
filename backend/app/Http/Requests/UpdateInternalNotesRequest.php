<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Internal notes: written by administrators only, read by administrators and the assigned employee. */
class UpdateInternalNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['notes' => ['present', 'nullable', 'string', 'max:5000']];
    }
}
