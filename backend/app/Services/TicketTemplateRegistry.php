<?php

namespace App\Services;

/** Reads config/ticket_templates.php: the work types and what each one starts with and expects. */
class TicketTemplateRegistry
{
    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(config('ticket_templates', []));
    }

    /** @return array<string, mixed>|null */
    public function find(?string $type): ?array
    {
        return $type !== null ? (config('ticket_templates')[$type] ?? null) : null;
    }

    /** @return list<string> */
    public function recommended(?string $type): array
    {
        return $this->find($type)['recommended'] ?? [];
    }

    /** @return list<array{key: string, label: string, brief_section: string|null, recommended: list<string>, acceptance: string, checklist: list<string>}> */
    public function all(): array
    {
        $out = [];
        foreach (config('ticket_templates', []) as $key => $template) {
            $out[] = ['key' => $key, ...array_intersect_key($template, array_flip(['label', 'brief_section', 'recommended', 'acceptance', 'checklist']))];
        }

        return $out;
    }
}
