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

    /** What the app needs to draw the forms. @return array<string, mixed> */
    public static function payload(): array
    {
        $types = [];
        foreach (self::types() as $key => $type) {
            $types[] = ['key' => $key, 'label' => $type['label'], 'fields' => $type['fields']];
        }

        return ['types' => $types, 'employee_request' => config('service_catalog.employee_request')];
    }
}
