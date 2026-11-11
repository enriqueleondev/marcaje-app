<x-layouts.app title="Importar alumnos">
    <div class="page-head">
        <div>
            <h1>Importar alumnos</h1>
            <p>Da de alta o actualiza muchos alumnos a la vez desde un archivo CSV.</p>
        </div>
        <a class="btn" href="{{ route('students.index') }}">Volver a alumnos</a>
    </div>

    <div class="two-col">
        <div>
            @if ($errs = session('import_errors'))
                <div class="sec" role="alert">
                    <h2 class="sec-title">No se importó nada: {{ count($errs) === 1 ? 'hay 1 error' : 'hay '.count($errs).' errores' }} en {{ session('import_file') }}</h2>
                    <p class="muted" style="margin-bottom:12px">Corrige estas líneas y vuelve a subir el archivo.</p>
                    <table class="table">
                        <thead><tr><th>Línea</th><th>Problema</th></tr></thead>
                        <tbody>
                            @foreach (array_slice($errs, 0, 100) as $e)
                                <tr><td class="mono">{{ $e['line'] }}</td><td>{{ $e['message'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if (count($errs) > 100)<p class="muted mono" style="margin-top:8px">Y {{ count($errs) - 100 }} errores más.</p>@endif
                </div>
            @endif

            <form method="POST" action="{{ route('students.import.store') }}" enctype="multipart/form-data" class="sec">
                @csrf
                <div class="field">
                    <label for="file">Archivo CSV</label>
                    <input class="input file" id="file" name="file" type="file" accept=".csv,text/csv" required
                           @error('file') aria-invalid="true" aria-describedby="file-error" @enderror>
                    @error('file')<p class="error" id="file-error">{{ $message }}</p>@enderror
                </div>
                <div class="form-actions"><button class="btn primary" type="submit">Importar</button></div>
            </form>
        </div>

        <aside class="sec">
            <h2 class="sec-title">Formato</h2>
            <p>Una fila por alumno con estas columnas:</p>
            <p class="mono code-line">matricula, nombre, apellido_paterno, apellido_materno, grado, grupo</p>
            <ul class="plain">
                <li>Si la matrícula ya existe, se actualizan sus datos.</li>
                <li>Si grado y grupo van vacíos, un alumno nuevo queda sin grupo y uno existente conserva el suyo.</li>
                <li>Si hay un solo error, no se guarda nada y te mostramos qué corregir.</li>
                <li>Funciona con archivos guardados desde Excel o Google Sheets.</li>
            </ul>
            <p style="margin-top:14px"><a class="btn" href="{{ route('students.import.template') }}">Descargar plantilla</a></p>

            @if ($recent->isNotEmpty())
                <h2 class="sec-title" style="margin-top:28px">Importaciones recientes</h2>
                <table class="table">
                    <tbody>
                        @foreach ($recent as $imp)
                            <tr>
                                <td class="mono">{{ $imp->created_at->timezone(\App\Models\Setting::get('timezone'))->format('d/m/Y H:i') }}</td>
                                <td>{{ $imp->filename }}</td>
                                <td class="mono">
                                    @if ($imp->errors)
                                        <span class="tag"><i class="sq falta"></i>{{ count($imp->errors) }} errores</span>
                                    @else
                                        <span class="tag"><i class="sq presente"></i>{{ $imp->rows_created }} nuevos, {{ $imp->rows_updated }} act.</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </aside>
    </div>
</x-layouts.app>
