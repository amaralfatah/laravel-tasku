<?php

namespace App\Enums;

enum ScopeType: string
{
    case ProjectOnly = 'project_only';
    case UnitSubtree = 'unit_subtree';

    public function label(): string
    {
        return match ($this) {
            self::ProjectOnly => 'Hanya project yang diikuti',
            self::UnitSubtree => 'Seluruh unit di bawahnya',
        };
    }
}
