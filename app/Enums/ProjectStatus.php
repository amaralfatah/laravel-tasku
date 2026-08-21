<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Berjalan',
            self::Completed => 'Selesai',
            self::Archived => 'Diarsipkan',
        };
    }
}
