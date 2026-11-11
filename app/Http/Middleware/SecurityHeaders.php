<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad para todas las respuestas.
 *
 * CSP con nonce por petición: los <script> en línea (tema, configuración del kiosco,
 * Livewire) llevan el nonce; Livewire lo toma de Vite::cspNonce(). El panel necesita
 * 'unsafe-eval' porque Alpine evalúa sus expresiones (x-data, @click); el kiosco y la
 * API no usan Alpine y van sin él.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // La cámara solo se permite en el kiosco (modo de lectura con cámara); en el resto, apagada.
        $camera = $request->is('kiosco', 'kiosco/*') ? 'camera=(self)' : 'camera=()';
        $response->headers->set('Permissions-Policy', "{$camera}, microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()");
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Sin CSP con el servidor de desarrollo de Vite (npm run dev) ni en la página de
        // excepción de depuración (solo existe con APP_DEBUG=true, nunca en producción).
        $debugErrorPage = config('app.debug') && $response->getStatusCode() >= 500;
        if (! Vite::isRunningHot() && ! $debugErrorPage) {
            $response->headers->set('Content-Security-Policy', $this->csp($request, $nonce));
        }

        return $response;
    }

    private function csp(Request $request, string $nonce): string
    {
        $alpine = $request->is('kiosco', 'kiosco/*', 'api/*') ? '' : " 'unsafe-eval'";

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$alpine}",
            "style-src 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "worker-src 'self'",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
