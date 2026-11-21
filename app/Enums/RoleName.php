<?php

namespace App\Enums;

enum RoleName: string
{
    case Administrator = 'administrator';
    case Tutor = 'tutor';
    case Assistant = 'assistant';
    case Guardian = 'guardian';
    case Teacher = 'teacher';
    case Coordinator = 'coordinator';
    case Student = 'student';

    /** @return list<string> */
    public static function delegatedAccountRoles(): array
    {
        return [self::Tutor->value, self::Assistant->value, self::Teacher->value, self::Guardian->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrador',
            self::Tutor => 'Tutor',
            self::Assistant => 'Auxiliar',
            self::Guardian => 'Apoderado',
            self::Teacher => 'Docente',
            self::Coordinator => 'Coordinador de tutoría',
            self::Student => 'Estudiante',
        };
    }
}
