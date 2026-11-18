<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Logo / collapsed logo / favicon upload. SVG is deliberately not accepted (see backend README): it can
 * carry script and there is no reliable sanitiser here. Content is sniffed by the `mimes` rule, not
 * trusted from the extension.
 */
class UploadAgencyBrandingAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $favicon = $this->route('kind') === 'favicon';

        return [
            'agency_id' => ['prohibited'],
            'file' => $favicon
                ? ['required', 'file', 'mimes:png,ico', 'max:512', 'dimensions:min_width=16,min_height=16,max_width=1024,max_height=1024']
                : ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $favicon = $this->route('kind') === 'favicon';

        return [
            'file.required' => 'Choose an image to upload.',
            'file.mimes' => $favicon ? 'The favicon must be a PNG or ICO file.' : 'The logo must be a PNG, JPG or WEBP image (SVG is not accepted).',
            'file.max' => $favicon ? 'The favicon may not be larger than 512 KB.' : 'The logo may not be larger than 2 MB.',
            'file.dimensions' => $favicon
                ? 'The favicon must be between 16x16 and 1024x1024 pixels.'
                : 'The logo must be between 64x64 and 4096x4096 pixels.',
        ];
    }
}
