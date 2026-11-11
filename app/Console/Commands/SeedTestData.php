<?php

namespace App\Console\Commands;

use App\Enums\ManualReason;
use App\Enums\Role;
use App\Models\AttendanceMark;
use App\Models\Course;
use App\Models\CourseSchedule;
use App\Models\Entry;
use App\Models\Group;
use App\Models\Kiosk;
use App\Models\RollCall;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\Credentials;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

/**
 * Datos de prueba realistas y 100% ficticios para el entorno local "igual a producción":
 * 3 grados × 4 grupos, ~300 alumnos, 15 docentes con clases y horarios, 2 kioscos y
 * dos semanas de entradas y pases de lista.
 *
 * BORRA la base de datos. Pide confirmación y se niega si APP_URL apunta a un dominio
 * que no sea local o un túnel de prueba.
 */
class SeedTestData extends Command
{
    protected $signature = 'marcaje:datos-prueba
        {--alumnos=25 : Alumnos por grupo}
        {--dias=10 : Días hábiles de historial}
        {--yes : No preguntar (para scripts)}
        {--sin-recrear : No recrea la base (lo usan las pruebas automatizadas)}';

    protected $description = 'Borra la base y la llena con datos de prueba ficticios (solo entornos locales)';

    private const FIRST = ['Ximena', 'Diego', 'Valeria', 'Emiliano', 'Renata', 'Santiago', 'Camila', 'Mateo', 'Regina', 'Leonardo', 'Natalia', 'Sebastián', 'Fernanda', 'Iker', 'Sofía', 'Joaquín', 'María José', 'Alejandro', 'Daniela', 'Rodrigo', 'Andrea', 'Bruno', 'Paula', 'Gael', 'Isabella', 'Héctor', 'Ana Paula', 'Tomás', 'Elena', 'Julián', 'Lucía', 'Marco', 'Abril', 'Nicolás', 'Victoria', 'Adrián', 'Mariana', 'Emilio', 'Jimena', 'Ángel'];

    private const LAST = ['Ortega', 'Hernández', 'Campos', 'Reyes', 'Vázquez', 'Morales', 'Domínguez', 'Guerrero', 'Fuentes', 'Castro', 'Ríos', 'Juárez', 'Delgado', 'Navarro', 'Cabrera', 'Herrera', 'Luna', 'Sandoval', 'Montes', 'Estrada', 'Salazar', 'Mendoza', 'Cervantes', 'Espinoza', 'Rangel', 'Zamora', 'Quiroz', 'Becerra', 'Aguilar', 'Arellano', 'Bautista', 'Cortés', 'Escobar', 'Galván', 'Lozano', 'Mora', 'Ochoa', 'Peña', 'Ponce', 'Tapia', 'Núñez', 'Ibarra'];

    private const SUBJECTS = ['Matemáticas', 'Química', 'Biología', 'Inglés', 'Lectura y Redacción', 'Física'];

    private const SLOTS = [['07:00', '07:50'], ['07:50', '08:40'], ['08:40', '09:30'], ['10:00', '10:50'], ['10:50', '11:40'], ['11:40', '12:30']];

    public function handle(Credentials $credentials): int
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?? '';
        $localHost = in_array($host, ['localhost', '127.0.0.1', ''], true) || str_ends_with($host, '.test') || str_ends_with($host, '.trycloudflare.com');
        if (! $localHost) {
            $this->error("APP_URL apunta a {$host}. Este comando borra la base: solo se usa en local.");

            return self::FAILURE;
        }
        if (! $this->option('yes') && ! confirm('Esto BORRA toda la base de datos y la llena con datos ficticios. ¿Continuar?', default: false)) {
            return self::FAILURE;
        }

        mt_srand(20261110);
        $tz = 'America/Mexico_City';
        if (! $this->option('sin-recrear')) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->info('Base recreada.');
        }
        $this->info('Generando datos…');

        Setting::put('institution_name', 'Plantel de Prueba');
        Setting::put('timezone', $tz);

        // Personal: contraseñas de prueba documentadas en docs/pruebas-produccion.md
        $password = 'prueba-2026';
        User::create(['role' => Role::Admin, 'first_name' => 'Gabriela', 'last_name_paternal' => 'Solís', 'last_name_maternal' => 'Ruiz', 'email' => 'direccion@marcaje.test', 'password' => $password, 'is_active' => true]);
        $teachers = collect(range(1, 15))->map(fn ($i) => User::create([
            'role' => Role::Teacher, 'first_name' => $this->pick(self::FIRST), 'last_name_paternal' => $this->pick(self::LAST), 'last_name_maternal' => $this->pick(self::LAST),
            'email' => "docente{$i}@marcaje.test", 'password' => $password, 'is_active' => true,
        ]));
        // Una cuenta con contraseña temporal vigente, para probar el cambio obligatorio
        $temp = $teachers[14]->issueTemporaryPassword();

        $groups = collect();
        foreach (range(1, 3) as $g) {
            foreach (range(1, 4) as $n) {
                $groups->push(Group::create(['grade' => $g, 'number' => $n]));
            }
        }

        $perGroup = (int) $this->option('alumnos');
        $enrollment = 20250000;
        DB::transaction(function () use ($groups, $perGroup, &$enrollment, $credentials) {
            foreach ($groups as $group) {
                foreach (range(1, $perGroup) as $_) {
                    $s = Student::create([
                        'first_name' => $this->pick(self::FIRST), 'last_name_paternal' => $this->pick(self::LAST), 'last_name_maternal' => $this->pick(self::LAST),
                        'enrollment_number' => (string) ($enrollment += mt_rand(3, 17)), 'group_id' => $group->id,
                    ]); // el QR se crea solo
                    if (mt_rand(1, 100) <= 80) {
                        $credentials->linkNfc($s, strtoupper(bin2hex(random_bytes(7))));
                    }
                }
            }
        });

        // Clases: cada grupo lleva las 6 materias, una por bloque, de lunes a viernes.
        // Docente por (materia, grado) para que nadie tenga dos grupos a la misma hora.
        foreach ($groups as $group) {
            foreach (self::SUBJECTS as $i => $subject) {
                $teacher = $teachers[($i * 3 + $group->grade - 1) % 15];
                $course = Course::create(['teacher_id' => $teacher->id, 'name' => $subject.' '.['I', 'II', 'III'][$group->grade - 1], 'group_id' => $group->id, 'room' => 'Aula '.chr(64 + $group->grade).'-'.str_pad((string) $group->number, 2, '0', STR_PAD_LEFT)]);
                $course->students()->attach($group->students()->pluck('id'));
                [$start, $end] = self::SLOTS[($i + $group->number) % 6];
                foreach (range(1, 5) as $day) {
                    $course->schedules()->create(['weekday' => $day, 'starts_at' => "{$start}:00", 'ends_at' => "{$end}:00"]);
                }
            }
        }
        // Las clases "existen" desde hace un mes para que los reportes cuenten las sesiones pasadas.
        $since = CarbonImmutable::now()->subMonth();
        Course::query()->update(['created_at' => $since]);
        DB::table('course_schedules')->update(['created_at' => $since]);

        $kioskTokens = collect([['Kiosco 1', 'Acceso principal'], ['Kiosco 2', 'Acceso de estacionamiento']])
            ->map(function ($k) {
                $kiosk = new Kiosk(['name' => $k[0], 'location' => $k[1]]);

                return [$kiosk, $kiosk->issueToken()];
            });

        $this->history((int) $this->option('dias'), $tz, $kioskTokens->pluck(0), $teachers);

        $this->newLine();
        $this->table(['Dato', 'Cantidad'], [
            ['Grupos', Group::count()], ['Alumnos', Student::count()], ['Con NFC', Student::has('activeNfc')->count()],
            ['Docentes', $teachers->count()], ['Clases', Course::count()], ['Entradas', Entry::count()],
            ['Pases de lista', RollCall::count()], ['Marcas', AttendanceMark::count()],
        ]);
        $this->line('Dirección: direccion@marcaje.test   Docentes: docente1@marcaje.test … docente14@marcaje.test   Contraseña: '.$password);
        $this->line("docente15@marcaje.test tiene contraseña temporal: {$temp}");
        $this->line('Códigos para vincular los kioscos (se muestran una sola vez):');
        foreach ($kioskTokens as [$kiosk, $token]) {
            $this->line("  {$kiosk->name}: {$token}");
        }

        return self::SUCCESS;
    }

    private function history(int $days, string $tz, $kiosks, $teachers): void
    {
        $dates = [];
        for ($d = CarbonImmutable::now($tz)->startOfDay(); count($dates) <= $days; $d = $d->subDay()) {
            if ($d->isWeekday()) {
                $dates[] = $d;
            }
        }
        $dates = array_reverse($dates);
        $today = CarbonImmutable::now($tz)->toDateString();
        $now = CarbonImmutable::now();
        $students = Student::with('activeNfc')->get();

        foreach ($dates as $day) {
            $rows = [];
            foreach ($students as $s) {
                if (mt_rand(1, 100) > 92) {
                    continue; // faltó a la escuela
                }
                $at = $day->setTime(6, 35)->addMinutes(mt_rand(0, 50))->addSeconds(mt_rand(0, 59));
                if ($at->gt($now->setTimezone($tz))) {
                    continue;
                }
                $manual = mt_rand(1, 100) <= 2;
                $rows[] = [
                    'student_id' => $s->id, 'kiosk_id' => $manual ? null : $kiosks[mt_rand(0, 1)]->id,
                    'method' => $manual ? 'manual' : ($s->activeNfc && mt_rand(1, 100) <= 75 ? 'nfc' : 'qr'),
                    'scanned_at' => $at->utc(), 'received_at' => $at->utc(), 'client_uuid' => $manual ? null : (string) Str::uuid(),
                    'created_by' => $manual ? $teachers[0]->id : null,
                    'manual_reason' => $manual ? ManualReason::Forgot->value : null, 'manual_note' => null,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                Entry::insert($chunk);
            }

            if ($day->toDateString() === $today) {
                continue; // las listas de hoy las pasa quien pruebe
            }
            $entryByStudent = Entry::onLocalDate($day)->orderBy('scanned_at')->get()->unique('student_id')->keyBy('student_id');
            foreach (CourseSchedule::with('course.students:id', 'course.teacher')->where('weekday', $day->isoWeekday())->get() as $schedule) {
                if (mt_rand(1, 100) <= 12) {
                    continue; // a veces no pasan lista
                }
                [$start, $end] = $schedule->windowOn($day->toDateString());
                $rc = RollCall::create(['course_id' => $schedule->course_id, 'course_schedule_id' => $schedule->id, 'date' => $day->toDateString(), 'starts_at' => $start, 'ends_at' => $end]);
                $marks = [];
                foreach ($schedule->course->students as $st) {
                    $entry = $entryByStudent->get($st->id);
                    $entry = $entry && $entry->scanned_at->lte($end) ? $entry : null;
                    $r = mt_rand(1, 100);
                    $status = $entry ? ($r <= 76 ? 'present' : ($r <= 84 ? 'late' : ($r <= 88 ? 'absent' : null))) : ($r <= 80 ? 'absent' : null);
                    $marks[] = ['roll_call_id' => $rc->id, 'student_id' => $st->id, 'entry_id' => $entry?->id, 'status' => $status,
                        'marked_by' => $status ? $schedule->course->teacher_id : null, 'marked_at' => $status ? $start->addMinutes(10) : null,
                        'created_at' => $start, 'updated_at' => $start];
                }
                AttendanceMark::insert($marks);
            }
        }
    }

    private function pick(array $from): string
    {
        return $from[mt_rand(0, count($from) - 1)];
    }
}
