<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe una ruta a uno o más roles. Uso: ->middleware('role:admin') o 'role:admin,teacher'.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $r) => Role::from($r), $roles);

        abort_unless($request->user()?->hasRole(...$allowed), 403);

        return $next($request);
    }
}
