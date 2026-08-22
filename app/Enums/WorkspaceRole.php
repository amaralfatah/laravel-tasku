<?php

namespace App\Enums;

/**
 * The reporting ladder inside a workspace, from BOD-1 down to BOD-4.
 *
 * Super admin sits above all of these and is not a workspace role at all: it
 * lives on `users.is_super_admin` and belongs to no company.
 *
 *   BOD-1  Kepala Divisi        runs the whole entity
 *   BOD-2  Kepala Sub Divisi    runs a sub division
 *   BOD-3  Asisten              supervises the work, owns projects
 *   BOD-4  ODS / Programmer     does the work
 *
 * BOD-1 through BOD-3 all have the same abilities; what separates them is the
 * slice of the org tree those abilities reach, and that comes from the
 * member's own `org_unit_id`. BOD-4 leads nobody and only ever reaches their
 * own tasks.
 */
enum WorkspaceRole: string
{
    case Bod1 = 'bod_1';
    case Bod2 = 'bod_2';
    case Bod3 = 'bod_3';
    case Bod4 = 'bod_4';

    public function label(): string
    {
        return match ($this) {
            self::Bod1 => 'Kepala Divisi',
            self::Bod2 => 'Kepala Sub Divisi',
            self::Bod3 => 'Asisten',
            self::Bod4 => 'ODS / Programmer',
        };
    }

    /**
     * Short badge form, e.g. `BOD-1`.
     */
    public function code(): string
    {
        return 'BOD-'.$this->rank();
    }

    /**
     * Position on the ladder; 1 is the most senior.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Bod1 => 1,
            self::Bod2 => 2,
            self::Bod3 => 3,
            self::Bod4 => 4,
        };
    }

    /**
     * The single role at the top of the entity. Its scope is the whole
     * workspace, and a workspace must always keep at least one.
     */
    public function isTop(): bool
    {
        return $this === self::Bod1;
    }

    /**
     * Leads other people: org units, membership, projects and monitoring — all
     * of it limited to the leader's own subtree. BOD-1, BOD-2 and BOD-3 reach
     * this bar; ODS does not.
     */
    public function managesTeam(): bool
    {
        return $this->rank() <= 3;
    }

    /**
     * Whether this role sits above another on the ladder. Nobody may hand out
     * a role at or above their own.
     */
    public function outranks(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    /**
     * Whether this role may hand out another one. Peers are allowed — a
     * Kepala Sub Divisi may appoint a second one for the same branch — but
     * nobody reaches above their own rank.
     */
    public function mayAssign(self $role): bool
    {
        return ! $role->outranks($this);
    }

    /**
     * Roles this one may invite people as, or promote someone to.
     *
     * @return array<int, self>
     */
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role): bool => $this->mayAssign($role),
        ));
    }
}
