<?php

namespace App\Services\Reports;

use App\Enums\Role;
use App\Models\User;
use LogicException;

/**
 * Who a report is for, derived from the authenticated user only (never from request input):
 * - admin: everything (optionally narrowed by the agency_id / client_id filters)
 * - owner: their own agency (optionally narrowed to one client)
 * - client: projects where they are the client, assignments they hold
 * `narrow()` can only make the scope smaller, so a filter can never widen access.
 */
final class ReportScope
{
    public const ADMIN = 'admin';

    public const OWNER = 'owner';

    public const CLIENT = 'client';

    private function __construct(
        public readonly string $kind,
        public readonly int $userId,
        public readonly ?int $agencyId,
        public readonly ?int $clientId,
        public readonly ?int $holderId,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return match ($user->role) {
            Role::Admin => new self(self::ADMIN, $user->id, null, null, null),
            Role::AgencyOwner => new self(self::OWNER, $user->id, (int) $user->agency_id, null, null),
            Role::AgencyClient => new self(self::CLIENT, $user->id, (int) $user->agency_id, $user->id, $user->id),
            default => throw new LogicException('This role has no reports.'),
        };
    }

    /** Applies the optional filters. Admin may pick an agency; admin and owner may pick a client. */
    public function narrow(ReportFilters $filters): self
    {
        $agencyId = $this->agencyId;
        $clientId = $this->clientId;

        if ($this->kind === self::ADMIN && $filters->agencyId !== null) {
            $agencyId = $filters->agencyId;
        }
        if ($this->kind !== self::CLIENT && $filters->clientId !== null) {
            $clientId = $filters->clientId;
        }

        return new self($this->kind, $this->userId, $agencyId, $clientId, $this->holderId);
    }

    public function isAdmin(): bool
    {
        return $this->kind === self::ADMIN;
    }

    /** True when nothing narrows the data (an admin with no agency or client filter). */
    public function isUnrestricted(): bool
    {
        return $this->agencyId === null && $this->clientId === null && $this->holderId === null;
    }

    /**
     * SQL fragment (starts with " AND ") limiting work_items aliased `$alias`, plus its bindings.
     *
     * @return array{0: string, 1: list<int>}
     */
    public function projectSql(string $alias = 'wi'): array
    {
        $sql = '';
        $bindings = [];
        if ($this->agencyId !== null) {
            $sql .= " AND {$alias}.agency_id = ?";
            $bindings[] = $this->agencyId;
        }
        if ($this->clientId !== null) {
            $sql .= " AND {$alias}.client_id = ?";
            $bindings[] = $this->clientId;
        }

        return [$sql, $bindings];
    }

    /**
     * SQL fragment limiting employee_assignments aliased `$alias`: the agency, and for clients (or an
     * owner/admin picking a client) the assignments that person holds.
     *
     * @return array{0: string, 1: list<int>}
     */
    public function assignmentSql(string $alias = 'ea'): array
    {
        $sql = '';
        $bindings = [];
        if ($this->agencyId !== null) {
            $sql .= " AND {$alias}.agency_id = ?";
            $bindings[] = $this->agencyId;
        }
        $holder = $this->holderId ?? $this->clientId;
        if ($holder !== null) {
            $sql .= " AND {$alias}.holder_id = ?";
            $bindings[] = $holder;
        }

        return [$sql, $bindings];
    }
}
