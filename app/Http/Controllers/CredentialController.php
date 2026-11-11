<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Student;
use App\Services\Credentials;
use App\Support\QrImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Credenciales: resumen, descargas del QR e impresión (individual o por grupo). Solo dirección.
 */
class CredentialController extends Controller
{
    public function index(): View
    {
        $total = Student::count();

        return view('credentials.index', [
            'total' => $total,
            'withQr' => Student::has('activeQr')->count(),
            'withNfc' => Student::has('activeNfc')->count(),
            'groups' => Group::ordered()->withCount([
                'students',
                'students as without_nfc_count' => fn ($q) => $q->doesntHave('activeNfc'),
            ])->get(),
            'ungrouped' => Student::whereNull('group_id')->count(),
        ]);
    }

    public function generateMissing(Credentials $credentials): RedirectResponse
    {
        $created = 0;
        Student::doesntHave('activeQr')->each(function (Student $s) use ($credentials, &$created) {
            $credentials->ensureQr($s);
            $created++;
        });

        return back()->with('status', $created === 1 ? 'Se generó 1 código QR.' : "Se generaron {$created} códigos QR.");
    }

    public function svg(Student $student): Response
    {
        $qr = $student->activeQr()->firstOrFail();

        return response(QrImage::svg($qr->value), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="qr-'.$this->slug($student).'.svg"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function png(Student $student): Response
    {
        $qr = $student->activeQr()->firstOrFail();

        return response(QrImage::png($qr->value), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="qr-'.$this->slug($student).'.png"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Hoja imprimible: un alumno, un grupo o los alumnos sin grupo. */
    public function print(Request $request): View
    {
        $query = Student::with(['group', 'activeQr'])->has('activeQr')->orderByName();
        $title = 'Credenciales';

        if ($request->filled('alumno')) {
            $query->whereKey($request->integer('alumno'));
        } elseif ($request->query('grupo') === 'sin') {
            $query->whereNull('group_id');
            $title = 'Credenciales sin grupo';
        } elseif ($request->filled('grupo')) {
            $group = Group::findOrFail($request->integer('grupo'));
            $query->where('group_id', $group->id);
            $title = "Credenciales {$group->short_name}";
        } else {
            abort(404);
        }

        $students = $query->get();
        abort_if($students->isEmpty(), 404);

        return view('credentials.print', ['students' => $students, 'title' => $title]);
    }

    private function slug(Student $student): string
    {
        return str($student->enrollment_number.' '.$student->last_name_paternal.' '.$student->first_name)->slug();
    }
}
