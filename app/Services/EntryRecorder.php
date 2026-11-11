<?php

namespace App\Services;

use App\Enums\ManualReason;
use App\Models\AuditLog;
use App\Models\Entry;
use App\Models\Kiosk;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Registra lecturas de kiosco como entradas.
 *
 * - Idempotente por client_uuid: si la cola offline reenvía una lectura, devuelve la misma entrada.
 * - Anti-duplicado: si el alumno ya tiene una entrada a menos de N minutos (configurable,
 *   5 por defecto) de esta lectura, no se guarda y se informa la hora de la anterior.
 * - La hora es la del dispositivo (scanned_at). Si viene adelantada se usa la del servidor;
 *   si tiene más de 7 días se rechaza.
 */
class EntryRecorder
{
    public const MAX_AGE_DAYS = 7;

    public function __construct(private Credentials $credentials) {}

    /**
     * @return array{status: 'ok'|'duplicate'|'unknown'|'invalid', entry?: Entry, student?: Student, previous?: Entry, method?: string}
     */
    public function record(Kiosk $kiosk, string $code, ?string $scannedAt, ?string $uuid): array
    {
        $now = CarbonImmutable::now();

        if ($uuid !== null && ! Str::isUuid($uuid)) {
            return ['status' => 'invalid'];
        }

        if ($uuid && $existing = Entry::with('student.group')->where('client_uuid', $uuid)->first()) {
            return ['status' => 'ok', 'entry' => $existing, 'student' => $existing->student, 'method' => $existing->method];
        }

        $at = $this->parseTime($scannedAt, $now);
        if ($at === null) {
            return ['status' => 'invalid'];
        }

        $credential = $this->credentials->findActiveByScan($code);
        if (! $credential) {
            return ['status' => 'unknown'];
        }

        $student = $credential->student;
        $window = (int) Setting::get('duplicate_window_minutes');

        $previous = Entry::where('student_id', $student->id)
            ->whereBetween('scanned_at', [$at->subMinutes($window), $at->addMinutes($window)])
            ->orderBy('scanned_at')
            ->first();

        if ($previous) {
            return ['status' => 'duplicate', 'student' => $student, 'previous' => $previous, 'method' => $credential->type->value];
        }

        try {
            $entry = Entry::create([
                'student_id' => $student->id,
                'kiosk_id' => $kiosk->id,
                'credential_id' => $credential->id,
                'method' => $credential->type->value,
                'scanned_at' => $at,
                'received_at' => $now,
                'client_uuid' => $uuid,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Llegó la misma lectura dos veces al mismo tiempo.
            $entry = Entry::where('client_uuid', $uuid)->firstOrFail();
        }

        return ['status' => 'ok', 'entry' => $entry, 'student' => $student, 'method' => $entry->method];
    }

    /**
     * Entrada registrada a mano por personal (olvidó o dañó su credencial, u otro motivo).
     * Aplica el mismo anti-duplicado que el kiosco. La hora debe ser de hoy y no futura.
     *
     * @return array{status: 'ok'|'duplicate', entry?: Entry, previous?: Entry}
     */
    public function recordManual(Student $student, ManualReason $reason, ?string $note, CarbonImmutable $at, User $by): array
    {
        $at = $at->utc(); // las horas se guardan en UTC; comparar en la misma zona
        $window = (int) Setting::get('duplicate_window_minutes');
        $previous = Entry::where('student_id', $student->id)
            ->whereBetween('scanned_at', [$at->subMinutes($window), $at->addMinutes($window)])
            ->orderBy('scanned_at')
            ->first();

        if ($previous) {
            return ['status' => 'duplicate', 'previous' => $previous];
        }

        $entry = Entry::create([
            'student_id' => $student->id,
            'method' => 'manual',
            'scanned_at' => $at,
            'received_at' => CarbonImmutable::now(),
            'created_by' => $by->id,
            'manual_reason' => $reason,
            'manual_note' => $note ?: null,
        ]);
        AuditLog::record('entry.manual', $entry, ['student_id' => $student->id, 'reason' => $reason->value], $by);

        return ['status' => 'ok', 'entry' => $entry];
    }

    private function parseTime(?string $value, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return $now;
        }

        try {
            $at = CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }

        if ($at->gt($now->addMinutes(2))) {
            return $now; // reloj de la tableta adelantado
        }

        return $at->lt($now->subDays(self::MAX_AGE_DAYS)) ? null : $at;
    }
}
