<?php

namespace App\Support;

/**
 * Genera contraseñas temporales fáciles de dictar o copiar: tres bloques de 4
 * caracteres en minúsculas sin caracteres ambiguos (ej. "tq7m-4kxe-r9pa", ~60 bits).
 */
final class TemporaryPassword
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $blocks = [];

        for ($b = 0; $b < 3; $b++) {
            $block = '';
            for ($i = 0; $i < 4; $i++) {
                $block .= self::ALPHABET[random_int(0, $max)];
            }
            $blocks[] = $block;
        }

        return implode('-', $blocks);
    }
}
