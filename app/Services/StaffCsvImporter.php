<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\CsvReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta masiva de cuentas del personal (docentes o dirección) desde CSV, en dos pasos:
 * vista previa con errores por fila y, si no hay errores, confirmación.
 *
 * Columnas: nombre, apellido_paterno, apellido_materno, correo.
 * No se repiten correos: ni dentro del archivo ni contra cuentas existentes (incluidas las dadas de baja).
 * Cada cuenta nueva recibe una contraseña temporal de 24 h que obliga a cambiarla.
 */
class StaffCsvImporter
{
    public const MAX_ROWS = 500;

    public const TEMPLATE_HEADER = ['nombre', 'apellido_paterno', 'apellido_materno', 'correo'];

    private const ALIASES = [
        'nombre' => 'nombre', 'nombres' => 'nombre', 'nombre_s' => 'nombre',
        'apellido_paterno' => 'paterno', 'paterno' => 'paterno', 'primer_apellido' => 'paterno',
        'apellido_materno' => 'materno', 'materno' => 'materno', 'segundo_apellido' => 'materno',
        'correo' => 'correo', 'correo_electronico' => 'correo', 'email' => 'correo', 'e_mail' => 'correo',
    ];

    /**
     * @return array{rows: list<array{line: int, first_name: string, last_name_paternal: string, last_name_maternal: ?string, email: string, error: ?string}>, errors: list<array{line: int, message: string}>}
     */
    public function preview(string $path): array
    {
        $errors = [];
        $raw = CsvReader::read($path, self::ALIASES,
            ['nombre' => 'nombre', 'paterno' => 'apellido_paterno', 'correo' => 'correo'],
            self::MAX_ROWS, 'El archivo no tiene cuentas, solo encabezados.', $errors);

        if ($errors) {
            return ['rows' => [], 'errors' => $errors];
        }

        $rows = array_map(fn ($r) => [
            'line' => $r['line'],
            'first_name' => $r['data']['nombre'] ?? '',
            'last_name_paternal' => $r['data']['paterno'] ?? '',
            'last_name_maternal' => ($r['data']['materno'] ?? '') ?: null,
            'email' => Str::lower($r['data']['correo'] ?? ''),
            'error' => null,
        ], $raw);

        return $this->validate($rows);
    }

    /**
     * Crea las cuentas de una vista previa sin errores. Vuelve a validar por si algo
     * cambió entre la vista previa y la confirmación.
     *
     * @return array{created: list<array{user: User, password: string}>, errors: list<array{line: int, message: string}>}
     */
    public function confirm(array $rows, Role $role, User $by): array
    {
        $checked = $this->validate(array_map(fn ($r) => [...$r, 'error' => null], $rows));
        if ($checked['errors']) {
            return ['created' => [], 'errors' => $checked['errors']];
        }

        $created = DB::transaction(function () use ($checked, $role, $by) {
            $out = [];
            foreach ($checked['rows'] as $r) {
                $user = User::create([
                    'role' => $role, 'first_name' => $r['first_name'], 'last_name_paternal' => $r['last_name_paternal'],
                    'last_name_maternal' => $r['last_name_maternal'], 'email' => $r['email'],
                    'password' => Str::random(40), 'is_active' => true,
                ]);
                $out[] = ['user' => $user, 'password' => $user->issueTemporaryPassword($by)];
            }

            return $out;
        });

        AuditLog::record('users.imported', null, ['role' => $role->value, 'count' => count($created)], $by);

        return ['created' => $created, 'errors' => []];
    }

    private function validate(array $rows): array
    {
        $errors = [];
        $seen = [];
        $emails = array_values(array_filter(array_column($rows, 'email')));
        $existing = User::withTrashed()->whereIn('email', $emails)->pluck('email')->map(fn ($e) => Str::lower($e))->flip();

        foreach ($rows as $i => $r) {
            $problems = [];
            if ($r['first_name'] === '') {
                $problems[] = 'falta el nombre';
            }
            if ($r['last_name_paternal'] === '') {
                $problems[] = 'falta el apellido paterno';
            }
            foreach (['first_name' => 'nombre', 'last_name_paternal' => 'apellido paterno', 'last_name_maternal' => 'apellido materno'] as $k => $label) {
                if (mb_strlen((string) $r[$k]) > 100) {
                    $problems[] = "el {$label} admite hasta 100 caracteres";
                }
            }

            $email = $r['email'];
            if ($email === '') {
                $problems[] = 'falta el correo';
            } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
                $problems[] = "el correo «{$email}» no es válido";
            } elseif (isset($seen[$email])) {
                $problems[] = "el correo se repite (también en la línea {$seen[$email]})";
            } elseif (isset($existing[$email])) {
                $problems[] = 'ya existe una cuenta con ese correo (puede estar dada de baja)';
            }
            $seen[$email] ??= $r['line'];

            if ($problems) {
                $rows[$i]['error'] = Str::ucfirst(implode('; ', $problems)).'.';
                $errors[] = ['line' => $r['line'], 'message' => $rows[$i]['error']];
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }
}
