<?php

namespace App\Services\Assistant;

use App\Models\User;

/** Questions every role can ask. */
class CommonAnswers extends AnswerSet
{
    private const ROLE_LABELS = [
        'admin' => 'Administrator',
        'team_lead' => 'Team lead',
        'qa' => 'QA',
        'employee' => 'Employee',
        'agency_owner' => 'Agency owner',
        'agency_member' => 'Agency member',
        'agency_client' => 'Agency client',
    ];

    public function questions(User $user): array
    {
        return [
            [
                'key' => 'my_account',
                'label' => 'Show my account details',
                'description' => 'Your role, agency and account status.',
                'category' => 'My account',
            ],
        ];
    }

    public function answer(string $key, User $user, array $params): ?array
    {
        if ($key !== 'my_account') {
            return null;
        }
        $user->loadMissing('agency');

        return [
            'title' => 'Your account',
            'blocks' => [
                $this->stats('Account', [
                    ['label' => 'Name', 'value' => $user->name],
                    ['label' => 'Role', 'value' => self::ROLE_LABELS[$user->role->value] ?? $user->role->value],
                    ['label' => 'Agency', 'value' => $user->agency?->name ?? 'Not part of an agency'],
                    ['label' => 'Member since', 'value' => $this->date((string) $user->created_at) ?? '—'],
                ]),
                $this->text('To change your name, photo or password, open My profile from the account menu.'),
            ],
        ];
    }
}
