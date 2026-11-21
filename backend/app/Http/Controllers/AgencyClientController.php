<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\AgencyClientRequest;
use App\Models\ClientProfile;
use App\Models\User;
use App\Services\AgencyContracts;
use App\Services\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The agency owner's "Clients" tab. Everything is scoped to the owner's own agency: a client of another agency
 * is a 404, never a 403, so ids cannot be probed.
 */
class AgencyClientController extends Controller
{
    private const OPEN = ['scheduled', 'in_progress'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $agencyId = $request->user()->agency_id;
        $search = trim((string) ($filters['search'] ?? ''));

        $clients = $this->scoped($agencyId)
            ->with('clientProfile')
            ->addSelect([
                'projects_count' => DB::table('work_items')->selectRaw('COUNT(*)')->whereColumn('work_items.client_id', 'users.id'),
                'last_activity_at' => DB::table('work_items')->selectRaw('MAX(updated_at)')->whereColumn('work_items.client_id', 'users.id'),
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn ($q) => $q->where('users.name', 'like', $like)->orWhere('users.email', 'like', $like)
                    ->orWhereHas('clientProfile', fn ($p) => $p->where('company_name', 'like', $like)));
            })
            ->when(($filters['status'] ?? null) !== null, fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('users.name')->orderBy('users.id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'clients' => collect($clients->items())->map(fn (User $client): array => $this->payload($client))->values(),
            'pagination' => [
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
                'per_page' => $clients->perPage(),
                'total' => $clients->total(),
            ],
            'limit' => $this->limit($agencyId),
        ]);
    }

    public function show(Request $request, int $client): JsonResponse
    {
        $user = $this->find($request, $client);
        $agencyId = $request->user()->agency_id;

        $projects = DB::table('work_items as w')->leftJoin('users as e', 'e.id', '=', 'w.employee_id')
            ->where('w.agency_id', $agencyId)->where('w.client_id', $user->id)
            ->orderByDesc('w.created_at')->limit(20)
            ->get(['w.id', 'w.title', 'w.status', 'w.due_date', 'e.name as employee_name'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'title' => $row->title, 'status' => $row->status, 'due_date' => $row->due_date ? substr((string) $row->due_date, 0, 10) : null, 'employee_name' => $row->employee_name]);
        $tickets = DB::table('tickets as t')->join('work_items as w', 'w.id', '=', 't.work_item_id')
            ->where('w.agency_id', $agencyId)->where('w.client_id', $user->id)
            ->selectRaw('t.status, COUNT(*) as n')->groupBy('t.status')->pluck('n', 'status');

        return response()->json(['client' => [
            ...$this->payload($user->loadMissing('clientProfile')),
            'projects' => $projects,
            'ticket_counts' => $tickets,
            'impact' => $this->impact($user),
        ]]);
    }

    public function store(AgencyClientRequest $request, UserAccountService $accounts): JsonResponse
    {
        $owner = $request->user();
        $data = $request->validated();
        $this->assertRoom($owner->agency_id);
        $send = (bool) ($data['send_invitation'] ?? true);

        $client = DB::transaction(function () use ($owner, $data, $send, $accounts): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Str::random(64),
                'role' => Role::AgencyClient,
                'agency_id' => $owner->agency_id,
                'is_active' => true,
            ]);
            $user->clientProfile()->create([...$this->profileFields($data), 'invited_at' => $send ? now() : null]);

            if ($send && $accounts->sendInvitation($user) !== Password::RESET_LINK_SENT) {
                throw new \RuntimeException('Unable to send the account invitation.');
            }

            return $user;
        });

        return response()->json(['client' => $this->payload($client->load('clientProfile'))], 201);
    }

    public function update(AgencyClientRequest $request, int $client, UserAccountService $accounts): JsonResponse
    {
        $user = $this->find($request, $client);
        $data = $request->validated();
        $oldEmail = $user->email;
        $emailChanged = isset($data['email']) && $data['email'] !== $oldEmail;
        $reactivating = array_key_exists('is_active', $data) && $data['is_active'] && ! $user->is_active;
        if ($reactivating) {
            $this->assertRoom($request->user()->agency_id);
        }

        $account = array_intersect_key($data, array_flip(['name', 'email', 'is_active']));
        // The shared updater handles sessions, held employees and the invitation when the email changes.
        $accounts->updateManagedUser($user, $account, $oldEmail, $emailChanged);
        $user->clientProfile()->updateOrCreate([], $this->profileFields($data));

        return response()->json(['client' => [
            ...$this->payload($user->refresh()->load('clientProfile')),
            'password_setup_email_sent' => $emailChanged,
        ]]);
    }

    /** Sends (or re-sends) the set-password invitation. */
    public function invite(Request $request, int $client, UserAccountService $accounts): JsonResponse
    {
        $user = $this->find($request, $client);
        abort_unless($user->is_active, 422, 'Reactivate this client before sending an invitation.');
        if ($accounts->sendInvitation($user) !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['email' => 'The invitation could not be sent. Try again in a minute.']);
        }
        $user->clientProfile()->updateOrCreate([], ['invited_at' => now()]);

        return response()->json(['message' => 'Invitation sent to '.$user->email.'.']);
    }

    public function destroy(Request $request, int $client, UserAccountService $accounts): JsonResponse
    {
        $accounts->deleteUser($this->find($request, $client));

        return response()->json(['message' => 'The client was permanently deleted.']);
    }

    private function scoped(int $agencyId)
    {
        return User::query()->where('agency_id', $agencyId)->where('role', Role::AgencyClient->value);
    }

    private function find(Request $request, int $id): User
    {
        return $this->scoped($request->user()->agency_id)->findOrFail($id);
    }

    /** @return array{used: int, allowed: int|null, reached: bool} */
    private function limit(int $agencyId): array
    {
        $allowed = AgencyContracts::limits(app(AgencyContracts::class)->forAgency($agencyId))['client_accounts'];
        $used = $this->scoped($agencyId)->where('is_active', true)->count();

        return ['used' => $used, 'allowed' => $allowed, 'reached' => $allowed !== null && $used >= $allowed];
    }

    private function assertRoom(int $agencyId): void
    {
        $limit = $this->limit($agencyId);
        if ($limit['reached']) {
            throw ValidationException::withMessages([
                'limit' => "Your plan includes {$limit['allowed']} client ".($limit['allowed'] === 1 ? 'account' : 'accounts').' and all are in use. Deactivate a client or ask your account manager for an upgrade.',
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function profileFields(array $data): array
    {
        return array_intersect_key($data, array_flip(ClientProfile::FIELDS));
    }

    /** What deactivating or deleting this client does: held employees are released and their open projects cancelled. */
    private function impact(User $client): array
    {
        $assignments = DB::table('employee_assignments')->where('holder_id', $client->id)->where('status', 'active')->pluck('id');
        $open = DB::table('work_items')->whereIn('assignment_id', $assignments)->whereIn('status', self::OPEN)->pluck('id');
        $hours = (float) DB::table('work_item_allocations')->whereIn('work_item_id', $open)->whereDate('work_date', '>=', now(config('app.timezone'))->toDateString())->sum('hours');

        return [
            'held_employees' => $assignments->count(),
            'open_projects' => $open->count(),
            'booked_hours' => round($hours, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(User $client): array
    {
        $profile = $client->clientProfile;

        return [
            'id' => $client->id,
            'name' => $client->name,
            'email' => $client->email,
            'is_active' => $client->is_active,
            'status' => $client->is_active ? 'active' : 'inactive',
            'projects_count' => (int) ($client->projects_count ?? 0),
            'last_activity_at' => isset($client->last_activity_at) ? \Illuminate\Support\Carbon::parse($client->last_activity_at)->toISOString() : null,
            'invited_at' => $profile?->invited_at?->toISOString(),
            ...collect(ClientProfile::FIELDS)->mapWithKeys(fn (string $f): array => [$f => $profile?->{$f}])->all(),
        ];
    }
}
