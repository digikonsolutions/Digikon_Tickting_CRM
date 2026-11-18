<?php

namespace App\Services\Assistant;

use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The in-app assistant ("Ask ClientHub"). Each role gets its own preset questions; a question that is not
 * in the caller's list answers 404, so one role can never run another role's question. Parameters are
 * validated against the options offered to that user (for example only the owner's own specialists).
 */
class AssistantService
{
    /** @return list<AnswerSet> */
    private function sets(User $user): array
    {
        $roleSet = match ($user->role) {
            Role::Admin => new AdminAnswers(),
            Role::AgencyOwner => new OwnerAnswers(),
            Role::AgencyClient => new ClientAnswers(),
            Role::AgencyMember => new MemberAnswers(),
            Role::Employee => new EmployeeAnswers(),
            default => null,
        };

        return array_values(array_filter([$roleSet, new CommonAnswers()]));
    }

    /** @return array{categories: list<array{name: string, questions: list<array<string, mixed>>}>} */
    public function questions(User $user): array
    {
        $categories = [];
        foreach ($this->sets($user) as $set) {
            foreach ($set->questions($user) as $question) {
                $categories[$question['category']][] = [
                    'key' => $question['key'],
                    'label' => $question['label'],
                    'description' => $question['description'],
                    'params' => $question['params'] ?? [],
                ];
            }
        }

        return ['categories' => array_map(
            fn (string $name, array $questions): array => ['name' => $name, 'questions' => $questions],
            array_keys($categories),
            array_values($categories)
        )];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{question_key: string, title: string, generated_at: string, blocks: list<array<string, mixed>>, empty_message: string|null}
     */
    public function ask(User $user, string $key, array $params): array
    {
        foreach ($this->sets($user) as $set) {
            $question = collect($set->questions($user))->firstWhere('key', $key);
            if ($question === null) {
                continue;
            }
            $clean = $this->validateParams($question['params'] ?? [], $params);
            $answer = $set->answer($key, $user, $clean);

            return [
                'question_key' => $key,
                'title' => $answer['title'],
                'generated_at' => CarbonImmutable::now(config('app.timezone'))->toIso8601String(),
                'blocks' => $answer['blocks'],
                'empty_message' => $answer['empty_message'] ?? null,
            ];
        }

        throw new NotFoundHttpException('That question is not available for your account.');
    }

    /**
     * Only declared parameters are kept; a select value must be one of the offered options.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function validateParams(array $definitions, array $params): array
    {
        $clean = [];
        foreach ($definitions as $definition) {
            $name = $definition['name'];
            $value = $params[$name] ?? null;
            if ($value === null || $value === '') {
                if (! empty($definition['required'])) {
                    throw ValidationException::withMessages(["params.{$name}" => "Choose a {$definition['label']}."]);
                }

                continue;
            }
            if (($definition['type'] ?? 'select') === 'select') {
                $allowed = array_map('strval', array_column($definition['options'] ?? [], 'value'));
                if (! in_array((string) $value, $allowed, true)) {
                    throw ValidationException::withMessages(["params.{$name}" => "That {$definition['label']} is not one you can ask about."]);
                }
            }
            $clean[$name] = $value;
        }

        return $clean;
    }
}
