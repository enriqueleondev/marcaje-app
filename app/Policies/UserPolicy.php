<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Gestión de cuentas del personal: solo dirección.
 * Nadie puede desactivarse ni darse de baja a sí mismo, y siempre queda
 * al menos una cuenta de dirección activa.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function issueTemporaryPassword(User $user, User $target): Response
    {
        if (! $user->isAdmin()) {
            return Response::deny();
        }

        return $user->is($target)
            ? Response::deny('Para cambiar tu propia contraseña usa "Cambiar contraseña".')
            : Response::allow();
    }

    public function revokeSessions(User $user, User $target): Response
    {
        if (! $user->isAdmin()) {
            return Response::deny();
        }

        return $user->is($target)
            ? Response::deny('Para cerrar tu propia sesión usa "Salir".')
            : Response::allow();
    }

    public function deactivate(User $user, User $target): Response
    {
        return $this->removalCheck($user, $target, 'desactivar');
    }

    public function delete(User $user, User $target): Response
    {
        return $this->removalCheck($user, $target, 'dar de baja');
    }

    public function changeRole(User $user, User $target): Response
    {
        return $this->removalCheck($user, $target, 'cambiar el rol de');
    }

    private function removalCheck(User $user, User $target, string $verb): Response
    {
        if (! $user->isAdmin()) {
            return Response::deny();
        }

        if ($user->is($target)) {
            return Response::deny("No puedes {$verb} tu propia cuenta.");
        }

        if ($target->isAdmin() && $target->is_active && self::activeAdmins() <= 1) {
            return Response::deny("No puedes {$verb} la última cuenta de dirección activa.");
        }

        return Response::allow();
    }

    private static function activeAdmins(): int
    {
        return User::where('role', Role::Admin)->where('is_active', true)->count();
    }
}
