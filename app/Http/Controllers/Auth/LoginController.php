<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const MAX_ATTEMPTS_PER_IP = 20;

    /** Bloqueo de 15 minutos tras agotar los intentos. */
    private const DECAY_SECONDS = 900;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Escribe un correo válido.',
            'password.required' => 'Escribe tu contraseña.',
        ]);

        $throttleKey = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());
        $ipKey = 'login-ip|'.$request->ip();

        // Dos límites: por cuenta+IP (5 intentos) y por IP (20), para frenar
        // también a quien prueba muchas cuentas distintas desde el mismo equipo.
        foreach ([[$throttleKey, self::MAX_ATTEMPTS], [$ipKey, self::MAX_ATTEMPTS_PER_IP]] as [$key, $max]) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }
            $seconds = RateLimiter::availableIn($key);
            AuditLog::record('auth.locked', null, ['email' => Str::limit($credentials['email'], 120, ''), 'ip' => $request->ip()]);

            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Vuelve a intentar en '.($seconds > 60 ? ceil($seconds / 60).' minutos.' : $seconds.' segundos.'),
            ]);
        }

        if (! Auth::attempt([...$credentials, 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);
            AuditLog::record('auth.failed', null, ['email' => Str::limit($credentials['email'], 120, ''), 'ip' => $request->ip()]);

            throw ValidationException::withMessages([
                'email' => 'El correo o la contraseña no coinciden.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        /** @var User $user */
        $user = Auth::user();

        if ($user->temporaryPasswordExpired()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Tu contraseña temporal venció. Pide una nueva a dirección.',
            ]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuditLog::record('auth.login', $user, ['ip' => $request->ip()], $user);

        if ($user->must_change_password) {
            return redirect()->route('password.edit');
        }

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
