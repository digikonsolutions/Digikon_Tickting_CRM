<?php

namespace App\Services\Assistant;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Legacy agency member questions: the people in their agency. */
class MemberAnswers extends AnswerSet
{
    public function questions(User $user): array
    {
        return [
            ['key' => 'member_agency_people', 'label' => 'Who is in my agency?', 'description' => 'How many owners, clients and members your agency has.', 'category' => 'My agency'],
        ];
    }

    public function answer(string $key, User $user, array $params): ?array
    {
        if ($key !== 'member_agency_people') {
            return null;
        }
        $counts = DB::table('users')
            ->where('agency_id', $user->agency_id)
            ->where('is_active', true)
            ->selectRaw('role, COUNT(*) AS n')
            ->groupBy('role')
            ->pluck('n', 'role');

        return [
            'title' => 'People in your agency',
            'blocks' => [
                $this->stats('Active accounts', [
                    ['label' => 'Owners', 'value' => (int) ($counts['agency_owner'] ?? 0)],
                    ['label' => 'Clients', 'value' => (int) ($counts['agency_client'] ?? 0)],
                    ['label' => 'Members', 'value' => (int) ($counts['agency_member'] ?? 0)],
                ]),
                $this->text('See names and contact details in the Agency directory.'),
            ],
        ];
    }
}
