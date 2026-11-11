<?php

namespace App\Enums;

/**
 * Estado que se muestra en el pase de lista y en reportes.
 * "No en aula" y "Falta" se guardan igual (absent); se distinguen por si hubo entrada.
 */
enum MarkState: string
{
    case Pending = 'confirmar';
    case Present = 'presente';
    case Late = 'retardo';
    case NotInClass = 'noaula';
    case Absent = 'falta';

    public static function fromDecision(?AttendanceStatus $status, bool $hasEntry): self
    {
        return match ($status) {
            null => $hasEntry ? self::Pending : self::Absent,
            AttendanceStatus::Present => self::Present,
            AttendanceStatus::Late => self::Late,
            AttendanceStatus::Absent => $hasEntry ? self::NotInClass : self::Absent,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Por confirmar',
            self::Present => 'Presente',
            self::Late => 'Retardo',
            self::NotInClass => 'No en aula',
            self::Absent => 'Falta',
        };
    }
}
