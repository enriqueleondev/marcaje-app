<?php

namespace App\Enums;

/**
 * Roles del personal. Los alumnos no son usuarios del sistema.
 * "admin" cubre root y directivo, que tienen los mismos permisos.
 */
enum Role: string
{
    case Admin = 'admin';
    case Teacher = 'teacher';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Dirección',
            self::Teacher => 'Docente',
        };
    }
}
