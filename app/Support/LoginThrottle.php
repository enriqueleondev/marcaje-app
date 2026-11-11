<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Límite de intentos de inicio de sesión.
 *
 * - Por cuenta: 5 fallos (desde cualquier IP) bloquean la cuenta 15 minutos.
 *   Dirección puede desbloquearla antes desde el panel.
 * - Por IP: 20 fallos bloquean esa IP 15 minutos (frena a quien prueba muchas cuentas).
 */
final class LoginThrottle
{
    public const MAX_PER_ACCOUNT = 5;

    public const MAX_PER_IP = 20;

    public const DECAY_SECONDS = 900;

    public static function accountKey(string $email): string
    {
        return 'login-account|'.Str::transliterate(Str::lower(trim($email)));
    }

    public static function ipKey(string $ip): string
    {
        return 'login-ip|'.$ip;
    }

    /** Segundos que le quedan al bloqueo de la cuenta, o 0 si no está bloqueada. */
    public static function accountLockedFor(string $email): int
    {
        $key = self::accountKey($email);

        return RateLimiter::tooManyAttempts($key, self::MAX_PER_ACCOUNT) ? RateLimiter::availableIn($key) : 0;
    }

    public static function unlockAccount(string $email): void
    {
        RateLimiter::clear(self::accountKey($email));
    }
}
