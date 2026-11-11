<?php

namespace App\Support;

/**
 * Escritura de CSV para Excel. Neutraliza la inyección de fórmulas: una celda que
 * empieza con =, +, -, @, tabulador o retorno se antepone con un apóstrofo para que
 * Excel/Sheets la muestren como texto y no la ejecuten (por ejemplo, un nombre de
 * alumno importado como "=HYPERLINK(...)").
 */
final class Csv
{
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** @param resource $handle */
    public static function row($handle, array $row): void
    {
        fputcsv($handle, array_map(self::cell(...), $row), ',', '"', '');
    }
}
