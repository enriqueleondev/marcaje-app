<x-layouts.app title="Ajustes">
    <div class="page-head">
        <div>
            <h1>Ajustes</h1>
            <p>Lo propio de esta institución. Cada escuela o empresa tiene su propia instalación.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('settings.update') }}" class="form-narrow" novalidate>
        @csrf @method('PUT')

        <x-field name="institution_name" label="Nombre de la institución" :value="$values['institution_name']" required maxlength="120"
                 hint="Aparece en el panel, en el kiosco y en las credenciales impresas." />

        <div class="field">
            <label for="timezone">Zona horaria</label>
            <select class="input" id="timezone" name="timezone">
                @unless (array_key_exists($values['timezone'], $timezones))
                    <option value="{{ $values['timezone'] }}" selected>{{ $values['timezone'] }}</option>
                @endunless
                @foreach ($timezones as $tz => $label)
                    <option value="{{ $tz }}" @selected(old('timezone', $values['timezone']) === $tz)>{{ $label }}</option>
                @endforeach
            </select>
            <p class="hint">Define la hora del kiosco, de los pases de lista y de los reportes.</p>
            @error('timezone')<p class="error">{{ $message }}</p>@enderror
        </div>

        <x-field name="duplicate_window_minutes" label="Anti-duplicado del kiosco (minutos)" type="number" min="1" max="60"
                 :value="$values['duplicate_window_minutes']" class="mono"
                 hint="Si el mismo alumno pasa otra vez dentro de este tiempo, no se registra otra entrada." />

        <div class="field-row">
            <x-field name="grades" label="Grados" type="number" min="1" max="12" :value="$values['grades']" class="mono" />
            <x-field name="groups_per_grade" label="Grupos por grado" type="number" min="1" max="26" :value="$values['groups_per_grade']" class="mono" />
        </div>
        <p class="hint" style="margin-top:6px">Hoy hay {{ $groupsCount }} grupos creados. Al aumentar, crea los nuevos desde <a class="link" href="{{ route('groups.index') }}">Grupos</a>; al reducir, los grupos existentes no se borran.</p>

        <div class="form-actions"><button class="btn primary" type="submit">Guardar ajustes</button></div>
    </form>
</x-layouts.app>
