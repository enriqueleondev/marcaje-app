<?php

namespace App\Services;

use App\Models\AttendanceMark;
use App\Models\Course;
use App\Models\Entry;
use App\Models\Setting;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reportes de asistencia sobre un rango de fechas (locales, inclusivo).
 *
 * Reglas:
 * - Presente, Retardo: lo que marcó el docente.
 * - Sin confirmar: tenía entrada y el docente no decidió. Cuenta como asistencia,
 *   pero se reporta aparte para ver qué docentes no pasan lista.
 * - No en aula: marcado como falta pero sí tenía entrada ("entró pero no llegó a clase").
 * - Falta: marcado como falta sin entrada, o sin decisión y sin entrada.
 */
class Reports
{
    public const MAX_DAYS = 120;

    /** Columnas agregadas por estado, en SQL portable (MySQL y SQLite). */
    private const STATE_SUMS = [
        'present' => "SUM(CASE WHEN attendance_marks.status = 'present' THEN 1 ELSE 0 END)",
        'late' => "SUM(CASE WHEN attendance_marks.status = 'late' THEN 1 ELSE 0 END)",
        'unconfirmed' => 'SUM(CASE WHEN attendance_marks.status IS NULL AND attendance_marks.entry_id IS NOT NULL THEN 1 ELSE 0 END)',
        'not_in_class' => "SUM(CASE WHEN attendance_marks.status = 'absent' AND attendance_marks.entry_id IS NOT NULL THEN 1 ELSE 0 END)",
        'absent' => "SUM(CASE WHEN (attendance_marks.status = 'absent' OR attendance_marks.status IS NULL) AND attendance_marks.entry_id IS NULL THEN 1 ELSE 0 END)",
        'total' => 'COUNT(*)',
    ];

    /** Normaliza el rango: fechas válidas, en orden y de máximo MAX_DAYS días. */
    public static function range(?string $from, ?string $to): array
    {
        $tz = Setting::get('timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $parse = fn (?string $d) => $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $d, $tz), null, false) : null;

        $end = $parse($to) ?? $today;
        $start = $parse($from) ?? $end->startOfWeek();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS);
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    /** Totales del rango. */
    public function totals(string $from, string $to, ?int $grade = null, ?int $group = null): array
    {
        $row = $this->marks($from, $to, $grade, $group)->selectRaw($this->sums())->first();
        $totals = collect(self::STATE_SUMS)->keys()->mapWithKeys(fn ($k) => [$k => (int) ($row->{$k} ?? 0)])->all();
        $totals['rate'] = self::rate($totals);
        $totals['entries'] = Entry::query()
            ->whereBetween('scanned_at', $this->utcBounds($from, $to))
            ->when($grade || $group, fn ($q) => $q->whereHas('student', fn ($s) => $s->inGradeGroup($grade, $group)))
            ->count();
        $totals['roll_calls'] = DB::table('roll_calls')->whereBetween('date', [$from, $to])->count();

        return $totals;
    }

    /**
     * Asistencia por alumno. Ordenado por porcentaje (los más bajos primero).
     *
     * @return Collection<int, object>
     */
    public function byStudent(string $from, string $to, ?int $grade = null, ?int $group = null, string $search = ''): Collection
    {
        $rows = $this->marks($from, $to, $grade, $group)
            ->selectRaw('attendance_marks.student_id, '.$this->sums())
            ->groupBy('attendance_marks.student_id')
            ->get()
            ->keyBy('student_id');

        $students = Student::withTrashed()->with('group')->whereIn('id', $rows->keys())->search($search)->get();

        return $students->map(function (Student $s) use ($rows) {
            $r = $rows[$s->id];
            $data = collect(self::STATE_SUMS)->keys()->mapWithKeys(fn ($k) => [$k => (int) $r->{$k}])->all();

            return (object) ['student' => $s, ...$data, 'rate' => self::rate($data)];
        })->sortBy([['rate', 'asc'], [fn ($a, $b) => strcmp($a->student->list_name, $b->student->list_name)]])->values();
    }

    /** "Entró pero no llegó a clase": faltas marcadas de alumnos que sí registraron entrada. */
    public function notInClass(string $from, string $to, ?int $grade = null, ?int $group = null): Collection
    {
        return AttendanceMark::query()
            ->where('attendance_marks.status', 'absent')
            ->whereNotNull('attendance_marks.entry_id')
            ->whereHas('rollCall', fn ($q) => $q->whereBetween('date', [$from, $to]))
            ->when($grade || $group, fn ($q) => $q->whereHas('student', fn ($s) => $s->inGradeGroup($grade, $group)))
            ->with(['student.group', 'entry', 'rollCall.course.teacher'])
            ->get()
            ->sortByDesc(fn ($m) => $m->rollCall->starts_at)
            ->values();
    }

    /** Entradas registradas a mano en el periodo, con motivo y quién las registró. */
    public function manualEntries(string $from, string $to, ?int $grade = null, ?int $group = null): Collection
    {
        return Entry::query()
            ->where('method', 'manual')
            ->whereBetween('scanned_at', $this->utcBounds($from, $to))
            ->when($grade || $group, fn ($q) => $q->whereHas('student', fn ($s) => $s->inGradeGroup($grade, $group)))
            ->with(['student.group', 'creator'])
            ->orderByDesc('scanned_at')
            ->get();
    }

    /**
     * Por clase: sesiones programadas que ya terminaron, cuántas tuvieron pase de lista
     * y cuántas entradas quedaron sin confirmar. Sirve para ver qué docentes no pasan lista.
     */
    public function byCourse(string $from, string $to): Collection
    {
        $tz = Setting::get('timezone');
        $now = CarbonImmutable::now();
        $courses = Course::with(['schedules', 'teacher', 'group'])->get();

        $opened = DB::table('roll_calls')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('course_schedule_id, date')
            ->get()
            ->map(fn ($r) => $r->course_schedule_id.'|'.substr($r->date, 0, 10))
            ->flip();

        $unconfirmed = DB::table('attendance_marks')
            ->join('roll_calls', 'roll_calls.id', '=', 'attendance_marks.roll_call_id')
            ->whereBetween('roll_calls.date', [$from, $to])
            ->whereNull('attendance_marks.status')->whereNotNull('attendance_marks.entry_id')
            ->groupBy('roll_calls.course_id')
            ->selectRaw('roll_calls.course_id, COUNT(*) as n')
            ->pluck('n', 'course_id');

        $days = CarbonPeriod::create($from, $to);

        return $courses->map(function (Course $c) use ($days, $opened, $unconfirmed, $now) {
            $expected = 0;
            $missing = 0;
            foreach ($days as $day) {
                $date = $day->toDateString();
                foreach ($c->schedules->where('weekday', $day->isoWeekday()) as $s) {
                    // Solo desde que existen la clase y ese horario: antes no había lista que pasar.
                    $since = max($c->created_at, $s->created_at)->timezone(Setting::get('timezone'))->toDateString();
                    if ($date < $since) {
                        continue;
                    }
                    [, $end] = $s->windowOn($date);
                    if ($end->gt($now)) {
                        continue; // aún no termina
                    }
                    $expected++;
                    if (! isset($opened[$s->id.'|'.$date])) {
                        $missing++;
                    }
                }
            }

            return (object) [
                'course' => $c,
                'expected' => $expected,
                'missing' => $missing,
                'unconfirmed' => (int) ($unconfirmed[$c->id] ?? 0),
            ];
        })->filter(fn ($r) => $r->expected > 0 || $r->unconfirmed > 0)
            ->sortBy([['missing', 'desc'], ['unconfirmed', 'desc']])
            ->values();
    }

    /**
     * Últimos N días con actividad en la escuela (algún registro de entrada) y si el
     * alumno entró ese día. Para el inspector de alumnos.
     *
     * @return list<array{date: string, entered: bool}>
     */
    public function recentDays(Student $student, int $days = 20): array
    {
        $tz = Setting::get('timezone');
        $since = CarbonImmutable::now($tz)->subDays($days * 2)->startOfDay()->utc();

        $localDate = fn ($at) => CarbonImmutable::parse($at, 'UTC')->setTimezone($tz)->toDateString();

        $schoolDays = Entry::where('scanned_at', '>=', $since)->pluck('scanned_at')
            ->map($localDate)->unique()->sort()->values()->slice(-$days)->values();
        $mine = $student->entries()->where('scanned_at', '>=', $since)->pluck('scanned_at')->map($localDate)->flip();

        return $schoolDays->map(fn ($d) => ['date' => $d, 'entered' => isset($mine[$d])])->all();
    }

    public static function rate(array $t): ?int
    {
        return $t['total'] ? (int) round(($t['present'] + $t['late'] + $t['unconfirmed']) / $t['total'] * 100) : null;
    }

    private function marks(string $from, string $to, ?int $grade, ?int $group): Builder
    {
        return DB::table('attendance_marks')
            ->join('roll_calls', 'roll_calls.id', '=', 'attendance_marks.roll_call_id')
            ->whereBetween('roll_calls.date', [$from, $to])
            ->when($grade || $group !== null, fn ($q) => $q->whereIn(
                'attendance_marks.student_id',
                Student::withTrashed()->inGradeGroup($grade, $group)->select('id'),
            ));
    }

    private function sums(): string
    {
        return collect(self::STATE_SUMS)->map(fn ($sql, $k) => "{$sql} as {$k}")->join(', ');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function utcBounds(string $from, string $to): array
    {
        $tz = Setting::get('timezone');

        return [
            CarbonImmutable::parse($from, $tz)->startOfDay()->utc(),
            CarbonImmutable::parse($to, $tz)->endOfDay()->utc(),
        ];
    }
}
