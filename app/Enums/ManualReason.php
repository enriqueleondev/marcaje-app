<?php

namespace App\Enums;

/** Motivo de una entrada registrada a mano (sin pasar credencial). */
enum ManualReason: string
{
    case Forgot = 'forgot';
    case Damaged = 'damaged';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Forgot => 'Olvidó su credencial',
            self::Damaged => 'Credencial dañada',
            self::Other => 'Otro',
        };
    }
}
