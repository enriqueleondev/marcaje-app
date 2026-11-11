<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Cambio de contraseña propio. Si la cuenta tiene contraseña temporal, este es
 * el único lugar al que se puede entrar hasta cambiarla.
 */
class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.change-password', [
            'forced' => $request->user()->must_change_password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'current_password.required' => $user->must_change_password ? 'Escribe la contraseña temporal que te dieron.' : 'Escribe tu contraseña actual.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.required' => 'Escribe la nueva contraseña.',
            'password.confirmed' => 'Las dos contraseñas nuevas no coinciden.',
            'password.different' => 'La nueva contraseña debe ser distinta de la actual.',
            'password.min' => 'La nueva contraseña debe tener al menos :min caracteres.',
        ]);

        $user->changePassword($request->string('password'));
        $request->session()->regenerate();

        return redirect()->route('home')->with('status', 'Contraseña actualizada.');
    }
}
