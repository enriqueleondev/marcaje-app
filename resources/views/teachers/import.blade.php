<x-layouts.app title="Importar personal">
    <div class="page-head">
        <div>
            <h1>Importar personal</h1>
            <p>Da de alta muchas cuentas de docentes o de dirección a la vez. Primero verás una vista previa; nada se crea hasta que confirmes.</p>
        </div>
        <a class="btn" href="{{ route('teachers.index') }}">Volver</a>
    </div>

    @error('file')<p class="notice is-error" role="alert" style="margin-top:16px">{{ $message }}</p>@enderror

    @if ($preview)
        @php($ok = count($preview['rows']) - count($preview['errors']))
        <section class="sec">
            <h2 class="sec-title">Vista previa de {{ $preview['filename'] }}, como {{ \App\Enums\Role::from($preview['role'])->label() === 'Docente' ? 'docentes' : 'dirección' }}</h2>
            @if ($preview['errors'])
                <p class="notice is-error" role="alert">{{ count($preview['errors']) === 1 ? 'Hay 1 fila con error' : 'Hay '.count($preview['errors']).' filas con error' }}. Corrige el archivo y vuelve a subirlo: no se creará ninguna cuenta mientras haya errores.</p>
            @else
                <p class="notice" role="status">{{ $ok === 1 ? 'Se creará 1 cuenta' : "Se crearán {$ok} cuentas" }}, cada una con una contraseña temporal de 24 horas.</p>
            @endif
            @if ($preview['rows'])
                <div class="table-wrap" style="margin-top:14px">
                    <table class="table">
                        <thead><tr><th>Línea</th><th>Nombre</th><th>Correo</th><th>Estado</th></tr></thead>
                        <tbody>
                            @foreach ($preview['rows'] as $r)
                                <tr>
                                    <td class="mono">{{ $r['line'] }}</td>
                                    <td>{{ trim($r['first_name'].' '.$r['last_name_paternal'].' '.$r['last_name_maternal']) ?: '—' }}</td>
                                    <td class="mono">{{ $r['email'] ?: '—' }}</td>
                                    <td>
                                        @if ($r['error'])
                                            <span class="tag"><i class="sq falta"></i>{{ $r['error'] }}</span>
                                        @else
                                            <span class="tag"><i class="sq presente"></i>Lista para crear</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                @foreach ($preview['errors'] as $e)<p class="error">Línea {{ $e['line'] }}: {{ $e['message'] }}</p>@endforeach
            @endif
            <div class="form-actions">
                @if (! $preview['errors'] && $preview['rows'])
                    <form method="POST" action="{{ route('teachers.import.confirm') }}">@csrf<button class="btn primary" type="submit">Crear {{ $ok === 1 ? '1 cuenta' : "{$ok} cuentas" }}</button></form>
                @endif
                <form method="POST" action="{{ route('teachers.import.cancel') }}">@csrf<button class="btn" type="submit">{{ $preview['errors'] ? 'Subir otro archivo' : 'Cancelar' }}</button></form>
            </div>
        </section>
    @endif

    @if (! $preview || $preview['errors'])
        <div class="two-col">
            <form method="POST" action="{{ route('teachers.import.preview') }}" enctype="multipart/form-data" class="sec" novalidate>
                @csrf
                <div class="field">
                    <span class="field-label" id="role-label">Importar como</span>
                    <div class="radio-row" role="radiogroup" aria-labelledby="role-label">
                        <label class="check"><input type="radio" name="role" value="teacher" @checked(old('role', 'teacher') === 'teacher')> Docentes</label>
                        <label class="check"><input type="radio" name="role" value="admin" @checked(old('role') === 'admin')> Dirección</label>
                    </div>
                    @error('role')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="file">Archivo CSV</label>
                    <input class="input file" id="file" name="file" type="file" accept=".csv,text/csv" required>
                </div>
                <div class="form-actions"><button class="btn primary" type="submit">Ver vista previa</button></div>
            </form>
            <aside class="sec">
                <h2 class="sec-title">Formato</h2>
                <p class="mono code-line">nombre, apellido_paterno, apellido_materno, correo</p>
                <ul class="plain">
                    <li>El correo es el usuario para entrar y no puede repetirse, ni en el archivo ni con cuentas existentes (incluidas las dadas de baja).</li>
                    <li>Cada cuenta recibe una contraseña temporal de 24 h que se muestra una sola vez al terminar.</li>
                    <li>Funciona con archivos guardados desde Excel o Google Sheets.</li>
                </ul>
                <p style="margin-top:14px"><a class="btn" href="{{ route('teachers.import.template') }}">Descargar plantilla</a></p>
            </aside>
        </div>
    @endif
</x-layouts.app>
