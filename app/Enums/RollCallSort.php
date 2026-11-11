<?php

namespace App\Enums;

enum RollCallSort: string
{
    case Seat = 'seat';
    case Paternal = 'paternal';
    case Maternal = 'maternal';
    case FirstName = 'first_name';
    case Arrival = 'arrival';

    public function label(): string
    {
        return match ($this) {
            self::Seat => 'Mi orden',
            self::Paternal => 'Apellido paterno',
            self::Maternal => 'Apellido materno',
            self::FirstName => 'Nombre',
            self::Arrival => 'Hora de llegada',
        };
    }
}
