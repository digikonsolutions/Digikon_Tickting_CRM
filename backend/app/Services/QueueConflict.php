<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** A queue change that would push projects past their due dates. Carries the projects and slips. */
class QueueConflict extends ValidationException
{
    /** @var list<array{work_item_id: int, title: string, slips_by_days: int, due_date: string}> */
    public array $conflicts = [];

    /**
     * @param  list<array{work_item_id: int, title: string, slips_by_days: int, due_date: string}>  $conflicts
     */
    public static function forConflicts(array $conflicts, string $field = 'ordered_work_item_ids'): self
    {
        $names = array_map(
            fn (array $c): string => $c['title'].' ('.$c['slips_by_days'].' '.($c['slips_by_days'] === 1 ? 'day' : 'days').' past its due date)',
            $conflicts
        );
        $exception = static::withMessages([
            $field => 'This change would push projects past their due dates: '.implode(', ', $names).'. Nothing was changed.',
        ]);
        $exception->conflicts = $conflicts;

        return $exception;
    }
}
