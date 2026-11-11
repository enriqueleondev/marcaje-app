<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Reports;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(): View
    {
        return view('reports.index');
    }

    /** CSV para Excel (UTF-8 con BOM). Mismos filtros que la pantalla. */
    public function export(Request $request, Reports $reports): StreamedResponse
    {
        [$from, $to] = Reports::range($request->query('desde'), $request->query('hasta'));
        $grade = $request->integer('grado') ?: null;
        $group = $request->has('grupo') ? $request->integer('grupo') : null;
        $tab = $request->query('ver', 'alumnos');
        $tz = Setting::get('timezone');

        [$name, $header, $rows] = match ($tab) {
            'no-en-aula' => [
                'entro-sin-llegar-a-clase',
                ['fecha', 'clase', 'docente', 'matricula', 'alumno', 'grupo', 'hora_entrada', 'metodo'],
                $reports->notInClass($from, $to, $grade, $group)->map(fn ($m) => [
                    $m->rollCall->starts_at->timezone($tz)->format('Y-m-d'), $m->rollCall->course->name, $m->rollCall->course->teacher?->full_name,
                    $m->student->enrollment_number, $m->student->list_name, $m->student->group?->short_name,
                    $m->entry?->scanned_at->timezone($tz)->format('H:i'), $m->entry?->methodLabel(),
                ]),
            ],
            'manuales' => [
                'entradas-manuales',
                ['fecha', 'hora', 'matricula', 'alumno', 'grupo', 'motivo', 'detalle', 'registro'],
                $reports->manualEntries($from, $to, $grade, $group)->map(fn ($e) => [
                    $e->scanned_at->timezone($tz)->format('Y-m-d'), $e->scanned_at->timezone($tz)->format('H:i'),
                    $e->student?->enrollment_number, $e->student?->list_name, $e->student?->group?->short_name,
                    $e->manual_reason?->label(), $e->manual_note, $e->creator?->full_name,
                ]),
            ],
            'clases' => [
                'clases-y-docentes',
                ['clase', 'grupo', 'docente', 'sesiones', 'sin_pase_de_lista', 'entradas_sin_confirmar'],
                $reports->byCourse($from, $to)->map(fn ($r) => [
                    $r->course->name, $r->course->group?->short_name, $r->course->teacher?->full_name, $r->expected, $r->missing, $r->unconfirmed,
                ]),
            ],
            default => [
                'asistencia-por-alumno',
                ['matricula', 'alumno', 'grupo', 'clases', 'presente', 'retardo', 'sin_confirmar', 'no_en_aula', 'falta', 'asistencia_pct'],
                $reports->byStudent($from, $to, $grade, $group, (string) $request->query('buscar', ''))->map(fn ($r) => [
                    $r->student->enrollment_number, $r->student->list_name, $r->student->group?->short_name,
                    $r->total, $r->present, $r->late, $r->unconfirmed, $r->not_in_class, $r->absent, $r->rate,
                ]),
            ],
        };

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            Csv::row($out, $header);
            foreach ($rows as $row) {
                Csv::row($out, $row);
            }
            fclose($out);
        }, "{$name}_{$from}_{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
