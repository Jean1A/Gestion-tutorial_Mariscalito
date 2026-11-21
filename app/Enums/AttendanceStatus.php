<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case JustifiedAbsence = 'justified_absence';
    case UnjustifiedAbsence = 'unjustified_absence';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Presente',
            self::Late => 'Tardanza',
            self::JustifiedAbsence => 'Falta justificada',
            self::UnjustifiedAbsence => 'Falta injustificada',
        };
    }
}
