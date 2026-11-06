<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgencyUpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['prohibited'],
            'role' => ['prohibited'],
            'agency_id' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];
    }
}
