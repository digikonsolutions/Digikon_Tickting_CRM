<?php

namespace App\Http\Requests\Concerns;

use App\Support\ServiceCatalog;
use Illuminate\Validation\Rule;

/**
 * `service_type` and `details` on projects and tickets: the type must be in the catalog and `details` may only hold
 * that type's fields, each checked by its own rule. Both stay optional, so existing clients keep working.
 */
trait ServiceDetailsRules
{
    /**
     * @param  'project'|'ticket'  $scope
     * @param  string|null  $existingType  the stored type when editing, used when the request does not send one
     * @return array<string, mixed>
     */
    protected function serviceDetailsRules(string $scope, ?string $existingType = null, bool $creating = true): array
    {
        $type = $this->has('service_type') ? $this->input('service_type') : $existingType;
        $type = is_string($type) ? $type : null;

        $rules = [
            'service_type' => ['nullable', 'string', Rule::in(ServiceCatalog::keys())],
            'details' => ['nullable', 'array', function (string $attribute, mixed $value, \Closure $fail) use ($type, $scope): void {
                if (! is_array($value) || $value === []) {
                    return;
                }
                if (! ServiceCatalog::has($type)) {
                    $fail('Choose a service type before adding details.');

                    return;
                }
                $unknown = array_diff(array_keys($value), ServiceCatalog::allowedKeys($type, $scope));
                if ($unknown !== []) {
                    $fail('These details do not belong to this service: '.implode(', ', array_map('strval', $unknown)).'.');
                }
            }],
        ];

        if (ServiceCatalog::has($type)) {
            $rules += ServiceCatalog::detailRules($type, $scope, $creating);
        }

        return $rules;
    }
}
