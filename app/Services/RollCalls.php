<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\RollCallSort;
use App\Models\AttendanceMark;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseSchedule;
use App\Models\Entry;
use App\Models\RollCall;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pase de lista: abrir, ligar entradas de kiosco, marcar estados y ordenar lugares.
 */
class RollCalls
{
    /** Clases de un día (local) para un usuario, ordenadas por hora. */
    public function classesOn(User $user, string $date): Collection
    {
        $weekday = CarbonImmutable::parse($date, Setting::get('timezone'))->isoWeekday();

        return CourseSchedule::query()
            ->where('weekday', $weekday)
            ->whereHas('course', fn ($q) => $q->where('is_active', true)->visibleTo($user))
            ->with(['course.group', 'course.teacher'])
            ->orderBy('starts_at')
            ->get();
    }

    /** Obtiene (o crea) el pase de lista de un bloque en una fecha y liga las entradas. */
    public function open(CourseSchedule $schedule, string $date): RollCall
    {
        [$start, $end] = $schedule->windowOn($date);

        $rollCall = RollCall::firstOrCreate(
            ['course_schedule_id' => $schedule->id, 'date' => $date],
            ['course_id' => $schedule->course_id, 'starts_at' => $start, 'ends_at' => $end],
        );

        $this->sync($rollCall);

        return $rollCall;
    }

    /**
     * Crea las marcas que falten y liga a cada alumno con su primera entrada del día
     * anterior al fin de la clase. Se llama al abrir y periódicamente mientras está abierta.
     */
    public function sync(RollCall $rollCall): void
    {
        $studentIds = $rollCall->course->students()->pluck('students.id');
        $existing = $rollCall->marks()->get()->keyBy('student_id');

        $entries = Entry::query()
            ->whereIn('student_id', $studentIds)
            ->onLocalDate($rollCall->starts_at)
            ->where('scanned_at', '<=', $rollCall->ends_at)
            ->orderBy('scanned_at')
            ->get()
            ->unique('student_id')
            ->keyBy('student_id');

        foreach ($studentIds as $id) {
            $entryId = $entries->get($id)?->id;
            $mark = $existing->get($id);

            if (! $mark) {
                $rollCall->marks()->create(['student_id' => $id, 'entry_id' => $entryId]);
            } elseif ($mark->entry_id === null && $entryId !== null) {
                $mark->update(['entry_id' => $entryId]);
            }
        }
    }

    /**
     * Marca a un alumno. $status null = quitar la decisión (vuelve a "Por confirmar" o "Falta").
     *
     * @throws ValidationException fuera del horario
     */
    public function mark(RollCall $rollCall, int $studentId, ?AttendanceStatus $status, User $by): AttendanceMark
    {
        $this->ensureOpen($rollCall);

        $mark = $rollCall->marks()->where('student_id', $studentId)->firstOrFail();
        $mark->update([
            'status' => $status,
            'marked_by' => $by->id,
            'marked_at' => now(),
        ]);

        return $mark;
    }

    /** "Confirmar todas las entradas": quien tiene entrada y sigue sin decisión pasa a Presente. */
    public function confirmAll(RollCall $rollCall, User $by): int
    {
        $this->ensureOpen($rollCall);

        $count = $rollCall->marks()->whereNull('status')->whereNotNull('entry_id')->update([
            'status' => AttendanceStatus::Present,
            'marked_by' => $by->id,
            'marked_at' => now(),
        ]);
        $rollCall->forceFill(['confirmed_all_at' => now()])->save();
        AuditLog::record('roll_call.confirmed_all', $rollCall, ['count' => $count], $by);

        return $count;
    }

    /**
     * Alumnos de la clase con su marca, en el orden pedido.
     *
     * @return Collection<int, array{student: Student, mark: ?AttendanceMark, entry: ?Entry}>
     */
    public function rows(RollCall $rollCall, RollCallSort $sort): Collection
    {
        $students = $this->seatOrder($rollCall->course);
        $marks = $rollCall->marks()->with('entry')->get()->keyBy('student_id');

        $rows = $students->map(fn (Student $s) => [
            'student' => $s,
            'mark' => $marks->get($s->id),
            'entry' => $marks->get($s->id)?->entry,
        ]);

        $collator = new \Collator('es_MX');
        $by = fn (callable $key) => $rows->sort(fn ($a, $b) => $collator->compare($key($a['student']), $key($b['student'])))->values();

        return match ($sort) {
            RollCallSort::Seat => $rows->values(),
            RollCallSort::Paternal => $by(fn ($s) => "{$s->last_name_paternal} {$s->last_name_maternal} {$s->first_name}"),
            RollCallSort::Maternal => $by(fn ($s) => "{$s->last_name_maternal} {$s->last_name_paternal} {$s->first_name}"),
            RollCallSort::FirstName => $by(fn ($s) => "{$s->first_name} {$s->last_name_paternal} {$s->last_name_maternal}"),
            RollCallSort::Arrival => $rows->sortBy(fn ($r) => $r['entry']?->scanned_at?->timestamp ?? PHP_INT_MAX)->values(),
        };
    }

    /** Alumnos en el orden del docente: primero los que tienen posición, luego alfabético. */
    public function seatOrder(Course $course): Collection
    {
        return $course->students()
            ->with('group')
            ->get()
            ->sortBy([
                fn ($a, $b) => ($a->pivot->position ?? PHP_INT_MAX) <=> ($b->pivot->position ?? PHP_INT_MAX),
                fn ($a, $b) => (new \Collator('es_MX'))->compare(
                    "{$a->last_name_paternal} {$a->last_name_maternal} {$a->first_name}",
                    "{$b->last_name_paternal} {$b->last_name_maternal} {$b->first_name}",
                ),
            ])
            ->values();
    }

    /** Mueve a un alumno a la posición $to (0 = primero) y guarda el orden completo. */
    public function moveSeat(Course $course, int $studentId, int $to): void
    {
        $ids = $this->seatOrder($course)->pluck('id')->values();
        $from = $ids->search($studentId);
        if ($from === false) {
            return;
        }

        $ids->splice($from, 1);
        $ids->splice(max(0, min($to, $ids->count())), 0, [$studentId]);

        DB::transaction(function () use ($course, $ids) {
            foreach ($ids as $i => $id) {
                $course->students()->updateExistingPivot($id, ['position' => $i + 1]);
            }
        });
    }

    public function resetSeats(Course $course): void
    {
        DB::table('course_student')->where('course_id', $course->id)->update(['position' => null]);
    }

    private function ensureOpen(RollCall $rollCall): void
    {
        if (! $rollCall->isOpen()) {
            throw ValidationException::withMessages([
                'roll_call' => "La lista solo se puede editar durante la clase ({$rollCall->localTime('starts_at')} a {$rollCall->localTime('ends_at')}).",
            ]);
        }
    }
}
