<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Menú lateral por rol. Un elemento solo aparece cuando su ruta existe,
 * así el menú crece solo conforme se agregan módulos.
 */
final class Navigation
{
    /** @var array<string, list<array{label: string, route: string, roles: list<Role>}>> */
    private const SECTIONS = [
        'Escuela' => [
            ['label' => 'Alumnos', 'route' => 'students.index', 'roles' => [Role::Admin, Role::Teacher]],
            ['label' => 'Docentes', 'route' => 'teachers.index', 'roles' => [Role::Admin]],
            ['label' => 'Grupos', 'route' => 'groups.index', 'roles' => [Role::Admin]],
        ],
        'Asistencia' => [
            ['label' => 'Pase de lista', 'route' => 'roll-calls.index', 'roles' => [Role::Admin, Role::Teacher]],
            ['label' => 'Entrada manual', 'route' => 'entries.manual', 'roles' => [Role::Admin, Role::Teacher]],
            ['label' => 'Clases', 'route' => 'courses.index', 'roles' => [Role::Admin, Role::Teacher]],
            ['label' => 'Credenciales', 'route' => 'credentials.index', 'roles' => [Role::Admin]],
            ['label' => 'Kioscos', 'route' => 'kiosks.index', 'roles' => [Role::Admin]],
            ['label' => 'Reportes', 'route' => 'reports.index', 'roles' => [Role::Admin]],
            ['label' => 'Ajustes', 'route' => 'settings.edit', 'roles' => [Role::Admin]],
        ],
    ];

    /** @return array<string, list<array{label: string, url: string, current: bool}>> */
    public static function for(User $user): array
    {
        $sections = ['' => [['label' => 'Inicio', 'route' => 'home', 'roles' => [Role::Admin, Role::Teacher]]]] + self::SECTIONS;
        $result = [];

        foreach ($sections as $title => $items) {
            $visible = [];
            foreach ($items as $item) {
                if (Route::has($item['route']) && $user->hasRole(...$item['roles'])) {
                    $visible[] = [
                        'label' => $item['label'],
                        'url' => route($item['route']),
                        'current' => request()->routeIs($item['route'], $item['route'].'.*'),
                    ];
                }
            }
            if ($visible) {
                $result[$title] = $visible;
            }
        }

        return $result;
    }
}
