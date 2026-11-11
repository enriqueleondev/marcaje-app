<?php

namespace App\Services;

use App\Http\Requests\StudentRequest;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta masiva de alumnos desde CSV.
 *
 * Columnas (en cualquier orden, con o sin acentos): matricula, nombre,
 * apellido_paterno, apellido_materno, grado, grupo.
 *
 * - Todo o nada: si alguna fila tiene error, no se guarda nada y se devuelve
 *   la lista de errores por número de línea.
 * - Si la matrícula ya existe, se actualizan sus datos (y se reactiva si estaba de baja).
 * - grado/grupo vacíos: alumno nuevo queda sin grupo; alumno existente conserva su grupo.
 * - Acepta coma, punto y coma o tabulador, y archivos en UTF-8 o Windows-1252 (Excel).
 */
class StudentCsvImporter
{
    public const MAX_ROWS = 5000;

    public const TEMPLATE_HEADER = ['matricula', 'nombre', 'apellido_paterno', 'apellido_materno', 'grado', 'grupo'];

    private const ALIASES = [
        'matricula' => 'matricula', 'no_matricula' => 'matricula', 'numero_de_control' => 'matricula', 'no_control' => 'matricula',
        'nombre' => 'nombre', 'nombres' => 'nombre', 'nombre_s' => 'nombre',
        'apellido_paterno' => 'paterno', 'paterno' => 'paterno', 'primer_apellido' => 'paterno',
        'apellido_materno' => 'materno', 'materno' => 'materno', 'segundo_apellido' => 'materno',
        'grado' => 'grado', 'semestre' => 'grado',
        'grupo' => 'grupo',
    ];

    private const REQUIRED = ['matricula', 'nombre', 'paterno'];

    /**
     * @return array{import: StudentImport, created: int, updated: int, errors: list<array{line: int, message: string}>}
     */
    public function import(string $path, string $filename, ?User $by = null): array
    {
        $errors = [];
        $rows = $this->parse($path, $errors);
        $valid = $errors ? [] : $this->validate($rows, $errors);

        $created = $updated = 0;

        if (! $errors) {
            DB::transaction(function () use ($valid, &$created, &$updated) {
                foreach ($valid as $row) {
                    $this->save($row) ? $created++ : $updated++;
                }
            });
        }

        $import = StudentImport::create([
            'user_id' => $by?->id,
            'filename' => Str::limit($filename, 250, ''),
            'rows_total' => count($rows),
            'rows_created' => $created,
            'rows_updated' => $updated,
            'errors' => $errors ?: null,
        ]);

        if (! $errors) {
            AuditLog::record('students.imported', $import, ['created' => $created, 'updated' => $updated], $by);
        }

        return ['import' => $import, 'created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /** @return list<array{line: int, data: array<string, string>}> */
    private function parse(string $path, array &$errors): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $lines = explode("\n", $content);
        $headerLine = $lines[0] ?? '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($headerLine, $d))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if (! $header || count(array_filter($header, fn ($h) => trim((string) $h) !== '')) === 0) {
            $errors[] = ['line' => 1, 'message' => 'El archivo está vacío.'];

            return [];
        }

        $columns = array_map(fn ($h) => self::ALIASES[self::normalizeHeader((string) $h)] ?? null, $header);
        $missing = array_diff(self::REQUIRED, $columns);
        if ($missing) {
            $names = ['matricula' => 'matricula', 'nombre' => 'nombre', 'paterno' => 'apellido_paterno'];
            $errors[] = ['line' => 1, 'message' => 'Faltan columnas: '.implode(', ', array_map(fn ($m) => $names[$m], $missing)).'. Descarga la plantilla para ver el formato.'];

            return [];
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($values === [null] || count(array_filter($values, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $data = [];
            foreach ($columns as $i => $key) {
                if ($key !== null) {
                    $data[$key] = StudentRequest::clean((string) ($values[$i] ?? '')) ?? '';
                }
            }
            $rows[] = ['line' => $line, 'data' => $data];

            if (count($rows) > self::MAX_ROWS) {
                $errors[] = ['line' => $line, 'message' => 'El archivo tiene más de '.self::MAX_ROWS.' filas. Divídelo en varios archivos.'];

                return [];
            }
        }
        fclose($handle);

        if (! $rows) {
            $errors[] = ['line' => 2, 'message' => 'El archivo no tiene alumnos, solo encabezados.'];
        }

        return $rows;
    }

    /** @return list<array{student: array<string, mixed>, group: ?array{0:int,1:int}, line: int}> */
    private function validate(array $rows, array &$errors): array
    {
        $grades = Group::grades();
        $numbers = Group::numbers();
        $seen = [];
        $valid = [];

        foreach ($rows as ['line' => $line, 'data' => $d]) {
            $rowErrors = [];
            $matricula = $d['matricula'] ?? '';

            if ($matricula === '') {
                $rowErrors[] = 'falta la matrícula';
            } elseif (mb_strlen($matricula) > 32) {
                $rowErrors[] = 'la matrícula admite hasta 32 caracteres';
            } elseif (isset($seen[mb_strtolower($matricula)])) {
                $rowErrors[] = "la matrícula {$matricula} se repite (también en la línea {$seen[mb_strtolower($matricula)]})";
            } else {
                $seen[mb_strtolower($matricula)] = $line;
            }

            if (($d['nombre'] ?? '') === '') {
                $rowErrors[] = 'falta el nombre';
            }
            if (($d['paterno'] ?? '') === '') {
                $rowErrors[] = 'falta el apellido paterno';
            }
            foreach (['nombre', 'paterno', 'materno'] as $k) {
                if (mb_strlen($d[$k] ?? '') > 100) {
                    $rowErrors[] = "el campo {$k} admite hasta 100 caracteres";
                }
            }

            $group = null;
            $grado = self::number($d['grado'] ?? '');
            $grupo = self::number($d['grupo'] ?? '');
            if (($d['grado'] ?? '') !== '' || ($d['grupo'] ?? '') !== '') {
                if ($grado === null || $grupo === null) {
                    $rowErrors[] = 'escribe grado y grupo como números (ej. 2 y 3), o deja ambos vacíos';
                } elseif (! in_array($grado, $grades, true) || ! in_array($grupo, $numbers, true)) {
                    $rowErrors[] = "el grupo {$grado}° {$grupo} no existe en esta escuela";
                } else {
                    $group = [$grado, $grupo];
                }
            }

            if ($rowErrors) {
                $errors[] = ['line' => $line, 'message' => Str::ucfirst(implode('; ', $rowErrors)).'.'];

                continue;
            }

            $valid[] = [
                'line' => $line,
                'group' => $group,
                'student' => [
                    'enrollment_number' => $matricula,
                    'first_name' => $d['nombre'],
                    'last_name_paternal' => $d['paterno'],
                    'last_name_maternal' => ($d['materno'] ?? '') ?: null,
                ],
            ];
        }

        return $valid;
    }

    /** @return bool true si se creó, false si se actualizó */
    private function save(array $row): bool
    {
        $attrs = $row['student'];
        if ($row['group']) {
            [$grade, $number] = $row['group'];
            $attrs['group_id'] = Group::firstOrCreate(['grade' => $grade, 'number' => $number])->id;
        }

        $student = Student::withTrashed()->where('enrollment_number', $attrs['enrollment_number'])->first();

        if ($student === null) {
            Student::create($attrs);

            return true;
        }

        if ($student->trashed()) {
            $student->restore();
        }
        $student->update($attrs);

        return false;
    }

    private static function number(string $value): ?int
    {
        // Acepta "2", "2°", "2.º", "2o"
        return preg_match('/^\s*(\d{1,2})\s*(?:\.?\s*[°ºo])?\s*$/u', $value, $m) ? (int) $m[1] : null;
    }

    private static function normalizeHeader(string $header): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii(trim($header)))), '_');
    }
}
