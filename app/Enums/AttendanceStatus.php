<?php

namespace App\Enums;

/** Lo que el docente decide. "Por confirmar" y "Falta" sin entrada no se guardan: se calculan. */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
}
