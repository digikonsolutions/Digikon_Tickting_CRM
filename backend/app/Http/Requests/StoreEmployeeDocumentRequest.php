<?php

namespace App\Http\Requests;

use App\Models\EmployeeDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The file itself (size, real MIME type) is checked by EmployeeDocumentService::problem(). */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'type' => ['required', Rule::in(EmployeeDocument::TYPES)],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
