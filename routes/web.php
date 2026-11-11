<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Kiosk\KioskPageController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RollCallController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffImportController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentImportController;
use Illuminate\Support\Facades\Route;

// Kiosco (PWA): página pública, la tableta se autentica con su token en la API.
Route::get('/kiosco', [KioskPageController::class, 'show'])->name('kiosk.show');
Route::get('/kiosco/manifest.webmanifest', [KioskPageController::class, 'manifest'])->name('kiosk.manifest');

Route::middleware('guest')->group(function () {
    Route::get('/entrar', [LoginController::class, 'create'])->name('login');
    Route::post('/entrar', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'account'])->group(function () {
    Route::post('/salir', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/contrasena', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/contrasena', [PasswordController::class, 'update'])->name('password.update');

    Route::get('/', HomeController::class)->name('home');

    // Docentes y dirección
    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('/alumnos', [StudentController::class, 'index'])->name('students.index');

        Route::get('/clases', [CourseController::class, 'index'])->name('courses.index');
        Route::post('/clases', [CourseController::class, 'store'])->name('courses.store');
        Route::get('/clases/{course}', [CourseController::class, 'show'])->name('courses.show');

        Route::view('/entrada-manual', 'entries.manual')->name('entries.manual');

        Route::get('/pase-de-lista', [RollCallController::class, 'index'])->name('roll-calls.index');
        Route::get('/pase-de-lista/{schedule}/{date}', [RollCallController::class, 'show'])->name('roll-calls.show');
    });

    // Solo dirección
    Route::middleware('role:admin')->group(function () {
        Route::get('/alumnos/nuevo', [StudentController::class, 'create'])->name('students.create');
        Route::post('/alumnos', [StudentController::class, 'store'])->name('students.store');
        Route::get('/alumnos/importar', [StudentImportController::class, 'create'])->name('students.import');
        Route::post('/alumnos/importar', [StudentImportController::class, 'store'])->name('students.import.store');
        Route::get('/alumnos/importar/plantilla.csv', [StudentImportController::class, 'template'])->name('students.import.template');
        Route::get('/alumnos/{student}/editar', [StudentController::class, 'edit'])->name('students.edit');
        Route::put('/alumnos/{student}', [StudentController::class, 'update'])->name('students.update');

        Route::get('/alumnos/{student}/qr.svg', [CredentialController::class, 'svg'])->name('students.qr.svg');
        Route::get('/alumnos/{student}/qr.png', [CredentialController::class, 'png'])->name('students.qr.png');

        Route::get('/credenciales', [CredentialController::class, 'index'])->name('credentials.index');
        Route::post('/credenciales/generar', [CredentialController::class, 'generateMissing'])->name('credentials.generate');
        Route::get('/credenciales/imprimir', [CredentialController::class, 'print'])->name('credentials.print');

        Route::get('/kioscos', [KioskController::class, 'index'])->name('kiosks.index');
        Route::post('/kioscos', [KioskController::class, 'store'])->name('kiosks.store');
        Route::get('/kioscos/{kiosk}', [KioskController::class, 'show'])->name('kiosks.show');
        Route::patch('/kioscos/{kiosk}', [KioskController::class, 'update'])->name('kiosks.update');
        Route::post('/kioscos/{kiosk}/token', [KioskController::class, 'regenerate'])->name('kiosks.regenerate');
        Route::post('/kioscos/{kiosk}/desvincular', [KioskController::class, 'revoke'])->name('kiosks.revoke');

        Route::get('/ajustes', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/ajustes', [SettingsController::class, 'update'])->name('settings.update');
        Route::put('/ajustes/kioscos', [SettingsController::class, 'updateKiosks'])->name('settings.kiosks');

        Route::get('/reportes', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reportes/exportar', [ReportController::class, 'export'])->name('reports.export');

        Route::get('/grupos', [GroupController::class, 'index'])->name('groups.index');
        Route::post('/grupos/predeterminados', [GroupController::class, 'storeDefaults'])->name('groups.defaults');
        Route::patch('/grupos/{group}', [GroupController::class, 'update'])->name('groups.update');
        Route::delete('/grupos/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');

        Route::get('/docentes', [StaffController::class, 'index'])->name('teachers.index');
        Route::get('/docentes/nuevo', [StaffController::class, 'create'])->name('teachers.create');
        Route::get('/docentes/importar', [StaffImportController::class, 'create'])->name('teachers.import');
        Route::post('/docentes/importar', [StaffImportController::class, 'preview'])->name('teachers.import.preview');
        Route::post('/docentes/importar/confirmar', [StaffImportController::class, 'confirm'])->name('teachers.import.confirm');
        Route::post('/docentes/importar/cancelar', [StaffImportController::class, 'cancel'])->name('teachers.import.cancel');
        Route::get('/docentes/importar/listo', [StaffImportController::class, 'done'])->name('teachers.import.done');
        Route::get('/docentes/importar/plantilla.csv', [StaffImportController::class, 'template'])->name('teachers.import.template');
        Route::post('/docentes', [StaffController::class, 'store'])->name('teachers.store');
        Route::get('/docentes/{user}', [StaffController::class, 'edit'])->name('teachers.edit');
        Route::put('/docentes/{user}', [StaffController::class, 'update'])->name('teachers.update');
        Route::post('/docentes/{user}/contrasena-temporal', [StaffController::class, 'temporaryPassword'])->name('teachers.temporary-password');
        Route::patch('/docentes/{user}/activo', [StaffController::class, 'toggleActive'])->name('teachers.toggle-active');
        Route::delete('/docentes/{user}', [StaffController::class, 'destroy'])->name('teachers.destroy');
        Route::post('/docentes/{user}/cerrar-sesiones', [StaffController::class, 'revokeSessions'])->name('teachers.revoke-sessions');
        Route::post('/docentes/{user}/desbloquear', [StaffController::class, 'unlock'])->name('teachers.unlock');
        Route::post('/docentes/{id}/reactivar', [StaffController::class, 'restore'])->whereNumber('id')->name('teachers.restore');
    });
});
