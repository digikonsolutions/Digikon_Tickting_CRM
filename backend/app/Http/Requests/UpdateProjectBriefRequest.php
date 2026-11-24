<?php

namespace App\Http\Requests;

use App\Support\GhlBrief;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing the detailed GoHighLevel brief of a project. Internal notes have no rule here on purpose: an agency user
 * cannot send them, and validated() drops anything that has no rule.
 */
class UpdateProjectBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [...GhlBrief::rules(), 'ghl_brief' => ['required', 'array']];
    }
}
