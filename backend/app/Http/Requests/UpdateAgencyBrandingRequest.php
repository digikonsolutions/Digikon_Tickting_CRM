<?php

namespace App\Http\Requests;

use App\Models\AgencyBranding;
use App\Support\ColorContrast;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Owner-only update of the text, colour and enum branding fields. Files have their own endpoints. */
class UpdateAgencyBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['display_name', 'tagline', 'welcome_message'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $value = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $value));
                $clean[$field] = $value === '' ? null : $value;
            }
        }
        foreach (['primary_color', 'accent_color'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $value = strtoupper(trim($value));
                $clean[$field] = $value === '' ? null : $value;
            }
        }
        $this->merge($clean);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'agency_id' => ['prohibited'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'welcome_message' => ['sometimes', 'nullable', 'string', 'max:240'],
            'primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme_mode' => ['sometimes', 'in:'.implode(',', AgencyBranding::THEME_MODES)],
            'sidebar_style' => ['sometimes', 'in:'.implode(',', AgencyBranding::SIDEBAR_STYLES)],
            'corner_style' => ['sometimes', 'in:'.implode(',', AgencyBranding::CORNER_STYLES)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['primary_color' => 'Primary', 'accent_color' => 'Accent'] as $field => $label) {
                    $value = $this->input($field);
                    if (! is_string($value) || $validator->errors()->has($field)) {
                        continue;
                    }
                    if (! ColorContrast::passesOnWhite($value)) {
                        $ratio = number_format(ColorContrast::ratio($value, '#FFFFFF'), 1);
                        $validator->errors()->add(
                            $field,
                            "{$label} colour is too light (contrast {$ratio}:1 against white, 4.5:1 needed). Pick a darker shade."
                        );
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'agency_id.prohibited' => 'The agency cannot be set; branding always applies to your own agency.',
            'primary_color.regex' => 'Primary colour must be a hex value like #0F766E.',
            'accent_color.regex' => 'Accent colour must be a hex value like #0F766E.',
            'display_name.max' => 'The display name may not be longer than 120 characters.',
            'tagline.max' => 'The tagline may not be longer than 160 characters.',
            'welcome_message.max' => 'The welcome message may not be longer than 240 characters.',
        ];
    }
}
