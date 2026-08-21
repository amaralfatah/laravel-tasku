<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Anggota',
        };
    }

    /**
     * Owner and admin manage the workspace; member only participates.
     */
    public function isManager(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
