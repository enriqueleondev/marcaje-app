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

    <section class="block" id="kioscos">
        <h2 class="block-title">Kioscos: modo de lectura</h2>
        @if ($kiosks->isEmpty())
            <p class="empty">Aún no hay kioscos. <a class="link" href="{{ route('kiosks.index') }}">Agrega uno.</a></p>
        @else
            <form method="POST" action="{{ route('settings.kiosks') }}" novalidate>
                @csrf @method('PUT')
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Kiosco</th><th>Modo</th></tr></thead>
                        <tbody>
                            @foreach ($kiosks as $k)
                                <tr>
                                    <td><span class="serif cell-name">{{ $k->name }}</span>@if ($k->location)<br><span class="muted">{{ $k->location }}</span>@endif</td>
                                    <td>
                                        <div class="radio-row" role="radiogroup" aria-label="Modo de {{ $k->name }}">
                                            @foreach (\App\Enums\KioskReadMode::cases() as $mode)
                                                <label class="check"><input type="radio" name="modes[{{ $k->id }}]" value="{{ $mode->value }}" @checked($k->read_mode === $mode)> {{ $mode->label() }}</label>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('modes.*')<p class="error">{{ $message }}</p>@enderror
                <ul class="plain" style="max-width:70ch">
                    <li><b>Lector USB:</b> lectores NFC y QR conectados a la tableta (el modo de siempre).</li>
                    <li><b>Cámara:</b> la cámara de la tableta o la webcam de la PC lee el QR. Pide permiso de cámara la primera vez.</li>
                    <li><b>Ambos:</b> la cámara queda activa y los lectores USB siguen funcionando.</li>
                </ul>
                <div class="form-actions"><button class="btn primary" type="submit">Guardar modos</button></div>
            </form>
        @endif
    </section>
</x-layouts.app>
