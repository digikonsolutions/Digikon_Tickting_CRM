<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Reads config/service_catalog.php: the GHL services, their fields, and the validation rules built from them.
 * The same definition is sent to the app (GET /api/agency/service-catalog), so form and server never disagree.
 */
class ServiceCatalog
{
    public const SCOPES = ['project', 'ticket'];

    /** @return array<string, array{label: string, fields: list<array<string, mixed>>}> */
    public static function types(): array
    {
        return config('service_catalog.types', []);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::types());
    }

    public static function has(?string $type): bool
    {
        return $type !== null && isset(self::types()[$type]);
    }

    /**
     * The fields asked for a type on the project or the (lighter) ticket form.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(string $type, string $scope): array
    {
        $fields = self::types()[$type]['fields'] ?? [];

        return array_values(array_filter($fields, fn (array $field): bool => $scope === 'project' || ($field['ticket'] ?? false)));
    }

    /**
     * Rules for every `details.<key>` of a type. Required fields only bind on a project that is being created.
     *
     * @return array<string, mixed>
     */
    public static function detailRules(string $type, string $scope, bool $creating): array
    {
        $rules = ['details.notes' => ['nullable', 'string', 'max:2000']];
        foreach (self::fields($type, $scope) as $field) {
            $key = 'details.'.$field['key'];
            $required = $creating && $scope === 'project' && ($field['required'] ?? false);
            $presence = $required ? ['required'] : ['nullable'];
            $allowed = array_keys($field['options'] ?? []);
            switch ($field['type']) {
                case 'select':
                    $rules[$key] = [...$presence, 'string', Rule::in($allowed)];
                    break;
                case 'checkbox':
                    $rules[$key] = [...$presence, 'array', ...($required ? ['min:1'] : []), 'max:'.count($allowed)];
                    $rules[$key.'.*'] = ['string', 'distinct', Rule::in($allowed)];
                    break;
                case 'url':
                    $rules[$key] = [...$presence, 'string', 'url:http,https', 'max:500'];
                    break;
                default:
                    $rules[$key] = [...$presence, 'string', 'max:'.($field['max'] ?? 200)];
            }
        }

        return $rules;
    }

    /** Keys a type accepts for a scope, including the free "anything else" note. @return list<string> */
    public static function allowedKeys(string $type, string $scope): array
    {
        return ['notes', ...array_column(self::fields($type, $scope), 'key')];
    }

    /** @return array{group: string, description: string, hours: array{0: int, 1: int}|null} */
    public static function meta(string $type): array
    {
        return config("service_catalog.services_meta.{$type}") ?? ['group' => 'other', 'description' => '', 'hours' => null];
    }

    /**
     * The rules for the `services` list of a new project: each entry is `{key, details, estimated_hours?}`, and its
     * `details` are checked by the same per-field rules as a single service, under `services.<i>.details.*`.
     *
     * @param  mixed  $services  the raw `services` input
     * @return array<string, mixed>
     */
    public static function servicesRules(mixed $services, bool $creating = true): array
    {
        $rules = [
            'services' => ['nullable', 'array', 'max:15'],
            'services.*' => ['array'],
            'services.*.key' => ['required', 'string', 'distinct', Rule::in(self::keys())],
            'services.*.estimated_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.25', 'max:'.config('scheduling.max_project_hours', 180)],
            'services.*.details' => ['nullable', 'array', function (string $attribute, mixed $value, \Closure $fail) use ($services): void {
                $index = (int) explode('.', $attribute)[1];
                $type = is_array($services) ? ($services[$index]['key'] ?? null) : null;
                if (! is_array($value) || $value === [] || ! self::has(is_string($type) ? $type : null)) {
                    return;
                }
                $unknown = array_diff(array_keys($value), self::allowedKeys($type, 'project'));
                if ($unknown !== []) {
                    $fail('These details do not belong to this service: '.implode(', ', array_map('strval', $unknown)).'.');
                }
            }],
        ];
        foreach (is_array($services) ? $services : [] as $index => $service) {
            $type = is_array($service) ? ($service['key'] ?? null) : null;
            if (! is_string($type) || ! self::has($type)) {
                continue;
            }
            foreach (self::detailRules($type, 'project', $creating) as $key => $rule) {
                // `details.trigger` becomes `services.0.details.trigger`.
                $rules["services.{$index}.".$key] = $rule;
            }
        }

        return $rules;
    }

    /** What the app needs to draw the forms. @return array<string, mixed> */
    public static function payload(): array
    {
        $types = [];
        foreach (self::types() as $key => $type) {
            $meta = self::meta($key);
            $types[] = ['key' => $key, 'label' => $type['label'], 'fields' => $type['fields'], 'group' => $meta['group'], 'description' => $meta['description'], 'hours' => $meta['hours']];
        }
        $groups = [];
        foreach (config('service_catalog.groups', []) as $key => $label) {
            $groups[] = ['key' => $key, 'label' => $label];
        }

        return ['types' => $types, 'groups' => $groups, 'packages' => config('service_catalog.packages', []), 'employee_request' => config('service_catalog.employee_request')];
    }
}
