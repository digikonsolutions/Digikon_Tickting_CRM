<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** A priority insert would push other projects past their due dates and the owner has not confirmed it yet. */
class RushImpactNotConfirmed extends ValidationException
{
    /** @var array<string, mixed> */
    public array $impact = [];

    /** @param  array<string, mixed>  $impact */
    public static function forImpact(array $impact): self
    {
        $exception = static::withMessages([
            'confirm_impact' => 'Other projects will move past their due dates. Confirm to go ahead.',
        ]);
        $exception->impact = $impact;

        return $exception;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'Other projects will move past their due dates. Confirm to go ahead.',
            'errors' => $this->errors(),
            'requires_confirmation' => true,
            'impact' => $this->impact,
        ], 422);
    }
}
