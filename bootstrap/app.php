<?php

use App\Http\Middleware\EnsureAccountIsUsable;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->group(base_path('routes/kiosk.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'account' => EnsureAccountIsUsable::class,
            'role' => EnsureRole::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        // Solo proxies locales (p. ej. cloudflared en la misma máquina). Confiar en
        // cualquiera permitiría falsear la IP y saltarse el límite de intentos por IP.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
