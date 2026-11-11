<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Para toda sesión iniciada:
 * - cierra la sesión si la cuenta se desactivó o dio de baja;
 * - cierra la sesión si la contraseña temporal venció;
 * - obliga a cambiar la contraseña temporal antes de usar el sistema.
 */
class EnsureAccountIsUsable
{
    private const ALLOWED_WHILE_TEMPORARY = ['password.edit', 'password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (! $user->is_active || $user->trashed()) {
            return $this->logout($request, 'Tu cuenta está suspendida. Habla con dirección.');
        }

        if ($user->temporaryPasswordExpired()) {
            return $this->logout($request, 'Tu contraseña temporal venció. Pide una nueva a dirección.');
        }

        if ($user->must_change_password && ! $request->routeIs(...self::ALLOWED_WHILE_TEMPORARY)) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
