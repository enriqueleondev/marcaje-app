<?php

use App\Http\Controllers\Kiosk\KioskApiController;
use App\Http\Middleware\AuthenticateKiosk;
use Illuminate\Support\Facades\Route;

// API de las tabletas: sin sesión ni CSRF; se autentican con su token.
Route::prefix('api/kiosco')->middleware([AuthenticateKiosk::class, 'throttle:kiosk'])->group(function () {
    Route::get('/estado', [KioskApiController::class, 'state'])->name('kiosk.api.state');
    Route::get('/padron', [KioskApiController::class, 'roster'])->name('kiosk.api.roster');
    Route::post('/lecturas', [KioskApiController::class, 'scans'])->name('kiosk.api.scans');
});
