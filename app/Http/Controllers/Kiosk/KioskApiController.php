<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Models\Entry;
use App\Models\Group;
use App\Models\Kiosk;
use App\Models\Setting;
use App\Models\Student;
use App\Services\EntryRecorder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API de la tableta. Todas las rutas pasan por AuthenticateKiosk.
 */
class KioskApiController extends Controller
{
    public const MAX_BATCH = 200;

    /** Datos para arrancar: nombre del kiosco, configuración y últimas 5 entradas. */
    public function state(Request $request): JsonResponse
    {
        $kiosk = $this->kiosk($request);

        $recent = $kiosk->entries()->onLocalDate()->with('student.group')
            ->orderByDesc('scanned_at')->limit(5)->get()
            ->map(fn (Entry $e) => $this->entryPayload($e, $e->student, $e->method));

        return response()->json([
            'kiosk' => ['name' => $kiosk->name, 'location' => $kiosk->location],
            'institution' => Setting::institutionName(),
            'timezone' => Setting::get('timezone'),
            'duplicate_window_minutes' => (int) Setting::get('duplicate_window_minutes'),
            'server_time' => now()->toIso8601String(),
            'today_count' => Entry::onLocalDate()->count(),
            'recent' => $recent,
        ]);
    }

    /**
     * Padrón para identificar alumnos sin conexión: credenciales activas de alumnos activos.
     * Si la versión del cliente coincide, no se reenvía.
     */
    public function roster(Request $request): JsonResponse
    {
        $version = $this->rosterVersion();
        if ($request->query('version') === $version) {
            return response()->json(['version' => $version, 'unchanged' => true]);
        }

        $students = Student::with('group')->has('credentials')->get(['id', 'first_name', 'last_name_paternal', 'last_name_maternal', 'group_id']);

        return response()->json([
            'version' => $version,
            'students' => $students->map(fn (Student $s) => $this->studentPayload($s))->values(),
            'credentials' => Credential::active()->whereIn('student_id', $students->pluck('id'))
                ->get(['student_id', 'type', 'value'])
                ->map(fn (Credential $c) => ['v' => $c->value, 's' => $c->student_id, 't' => $c->type->value])->values(),
        ]);
    }

    /** Recibe una o varias lecturas (la cola offline se envía en lote). */
    public function scans(Request $request, EntryRecorder $recorder): JsonResponse
    {
        $data = $request->validate([
            'scans' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'scans.*.uuid' => ['required', 'string', 'max:36'],
            'scans.*.code' => ['required', 'string', 'max:128'],
            'scans.*.scanned_at' => ['nullable', 'string', 'max:40'],
        ]);

        $kiosk = $this->kiosk($request);

        $results = collect($data['scans'])->map(function (array $scan) use ($kiosk, $recorder) {
            $r = $recorder->record($kiosk, $scan['code'], $scan['scanned_at'] ?? null, $scan['uuid']);

            return ['uuid' => $scan['uuid'], 'status' => $r['status']] + match ($r['status']) {
                'ok' => $this->entryPayload($r['entry'], $r['student'], $r['method']),
                'duplicate' => [
                    'student' => $this->studentPayload($r['student']),
                    'method' => strtoupper($r['method']),
                    'previous_time' => $this->localTime($r['previous']->scanned_at),
                ],
                default => [],
            };
        });

        return response()->json(['results' => $results, 'today_count' => Entry::onLocalDate()->count()]);
    }

    private function entryPayload(Entry $entry, Student $student, string $method): array
    {
        return [
            'student' => $this->studentPayload($student),
            'method' => strtoupper($method),
            'time' => $this->localTime($entry->scanned_at, true),
        ];
    }

    private function studentPayload(Student $s): array
    {
        return [
            'id' => $s->id,
            'n' => $s->full_name,
            'f' => $s->first_name,
            'p' => $s->last_name_paternal,
            'g' => $s->group?->short_name,
            'i' => $s->initials,
        ];
    }

    private function localTime(\DateTimeInterface $at, bool $seconds = false): string
    {
        return Carbon::instance($at)->timezone(Setting::get('timezone'))->format($seconds ? 'H:i:s' : 'H:i');
    }

    private function rosterVersion(): string
    {
        return substr(sha1(implode('|', [
            Credential::max('updated_at'), Credential::count(), Credential::active()->count(),
            Student::withTrashed()->max('updated_at'), Student::count(),
            Group::max('updated_at'),
        ])), 0, 16);
    }

    private function kiosk(Request $request): Kiosk
    {
        return $request->attributes->get('kiosk');
    }
}
