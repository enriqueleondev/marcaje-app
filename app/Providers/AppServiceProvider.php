<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Si la URL pública es https, todos los enlaces y assets salen con https://
        // (detrás de un túnel local también lo detecta por X-Forwarded-Proto).
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        Gate::define('admin', fn (User $user) => $user->isAdmin());
        Gate::define('teacher', fn (User $user) => $user->isTeacher());

        // Cada kiosco: hasta 240 llamadas por minuto (una fila de alumnos + sincronización).
        RateLimiter::for('kiosk', fn (Request $request) => Limit::perMinute(240)
            ->by(optional($request->attributes->get('kiosk'))->id ?? $request->ip()));
    }
}
