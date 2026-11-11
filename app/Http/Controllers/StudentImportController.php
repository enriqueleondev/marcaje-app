<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentImport;
use App\Services\StudentCsvImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentImportController extends Controller
{
    public function create(): View
    {
        $this->authorize('import', Student::class);

        return view('students.import', [
            'recent' => StudentImport::with('user')->latest('id')->limit(5)->get(),
        ]);
    }

    public function store(Request $request, StudentCsvImporter $importer): RedirectResponse
    {
        $this->authorize('import', Student::class);

        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ], [
            'file.required' => 'Elige un archivo CSV.',
            'file.max' => 'El archivo pesa más de 2 MB.',
            'file.mimes' => 'El archivo debe ser .csv. En Excel: Archivo › Guardar como › CSV.',
        ]);

        $file = $request->file('file');
        $result = $importer->import($file->getRealPath(), $file->getClientOriginalName(), $request->user());

        if ($result['errors']) {
            return back()->with('import_errors', $result['errors'])
                ->with('import_file', $file->getClientOriginalName());
        }

        return redirect()->route('students.index')->with('status', sprintf(
            'Importación lista: %d %s y %d %s.',
            $result['created'], $result['created'] === 1 ? 'alumno nuevo' : 'alumnos nuevos',
            $result['updated'], $result['updated'] === 1 ? 'actualizado' : 'actualizados',
        ));
    }

    public function template(): StreamedResponse
    {
        $this->authorize('import', Student::class);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea los acentos
            fputcsv($out, StudentCsvImporter::TEMPLATE_HEADER, ',', '"', '');
            fputcsv($out, ['20250001', 'Ximena', 'Ortega', 'Ruiz', '1', '1'], ',', '"', '');
            fputcsv($out, ['20250002', 'Diego', 'Hernández', 'Soto', '1', '2'], ',', '"', '');
            fclose($out);
        }, 'plantilla-alumnos.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
