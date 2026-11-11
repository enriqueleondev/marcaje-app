<?php

namespace App\Http\Middleware;

use App\Models\Kiosk;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica una tableta por su token (Authorization: Bearer mk_...).
 */
class AuthenticateKiosk
{
    public function handle(Request $request, Closure $next): Response
    {
        $kiosk = Kiosk::findByToken($request->bearerToken());

        if (! $kiosk) {
            return response()->json(['message' => 'Este kiosco no está vinculado o fue desvinculado.'], 401);
        }

        if (! $kiosk->last_seen_at || $kiosk->last_seen_at->lt(now()->subMinute())) {
            $kiosk->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $request->attributes->set('kiosk', $kiosk);

        return $next($request);
    }
}
