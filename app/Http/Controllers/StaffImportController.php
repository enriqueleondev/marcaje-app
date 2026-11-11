<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Services\StaffCsvImporter;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importación masiva de docentes o dirección: subir → vista previa → confirmar.
 * La vista previa se guarda en la sesión (cifrada en producción) hasta confirmar.
 */
class StaffImportController extends Controller
{
    private const SESSION_KEY = 'staff_import';

    public function create(Request $request): View
    {
        $this->authorize('create', User::class);

        return view('teachers.import', ['preview' => $request->session()->get(self::SESSION_KEY)]);
    }

    public function preview(Request $request, StaffCsvImporter $importer): RedirectResponse
    {
        $this->authorize('create', User::class);
        $data = $request->validate([
            'role' => ['required', Rule::enum(Role::class)],
            'file' => ['required', 'file', 'max:1024', 'mimes:csv,txt'],
        ], [
            'role.required' => 'Elige si son docentes o dirección.',
            'file.required' => 'Elige un archivo CSV.',
            'file.max' => 'El archivo pesa más de 1 MB.',
            'file.mimes' => 'El archivo debe ser .csv. En Excel: Archivo › Guardar como › CSV.',
        ]);

        $result = $importer->preview($request->file('file')->getRealPath());
        $request->session()->put(self::SESSION_KEY, [
            'role' => $data['role'],
            'filename' => mb_substr($request->file('file')->getClientOriginalName(), 0, 120),
            'rows' => $result['rows'],
            'errors' => $result['errors'],
        ]);

        return redirect()->route('teachers.import');
    }

    public function confirm(Request $request, StaffCsvImporter $importer): RedirectResponse
    {
        $this->authorize('create', User::class);
        $preview = $request->session()->get(self::SESSION_KEY);
        if (! $preview || $preview['errors'] || ! $preview['rows']) {
            return redirect()->route('teachers.import')->withErrors(['file' => 'Sube un archivo sin errores antes de confirmar.']);
        }

        $result = $importer->confirm($preview['rows'], Role::from($preview['role']), $request->user());
        if ($result['errors']) {
            $request->session()->put(self::SESSION_KEY, [...$preview, 'errors' => $result['errors']]);

            return redirect()->route('teachers.import')->withErrors(['file' => 'Algo cambió desde la vista previa (por ejemplo, un correo ya se usó). Revisa los errores.']);
        }

        $request->session()->forget(self::SESSION_KEY);

        // Las contraseñas temporales se muestran una sola vez.
        return redirect()->route('teachers.import.done')->with('staff_import_done', [
            'role' => $preview['role'],
            'accounts' => array_map(fn ($c) => ['name' => $c['user']->full_name, 'email' => $c['user']->email, 'password' => $c['password']], $result['created']),
        ]);
    }

    public function done(Request $request): View|RedirectResponse
    {
        $this->authorize('create', User::class);
        $done = $request->session()->get('staff_import_done');

        return $done ? view('teachers.import-done', ['done' => $done]) : redirect()->route('teachers.index');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('teachers.import');
    }

    public function template(): StreamedResponse
    {
        $this->authorize('create', User::class);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            Csv::row($out, StaffCsvImporter::TEMPLATE_HEADER);
            Csv::row($out, ['Laura', 'Pineda', 'Ávila', 'laura.pineda@escuela.mx']);
            Csv::row($out, ['Martín', 'Cárdenas', '', 'martin.cardenas@escuela.mx']);
            fclose($out);
        }, 'plantilla-personal.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
