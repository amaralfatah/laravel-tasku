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
     * The single role at the top of the entity. A workspace must always keep
     * at least one, and only this role may change other people's roles.
     */
    public function isTop(): bool
    {
        return $this === self::Bod1;
    }

    /**
     * Runs the organisation: org units, invitations, membership, and every
     * project in the workspace.
     */
    public function isManager(): bool
    {
        return $this->rank() <= 2;
    }

    /**
     * Owns project delivery: create, edit and staff projects, and delete any
     * task inside them. Asisten reaches this bar; ODS does not.
     */
    public function managesProjects(): bool
    {
        return $this->rank() <= 3;
    }
}
