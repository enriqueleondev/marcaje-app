<x-layouts.app title="Credenciales">
    <div class="page-head">
        <div>
            <h1>Credenciales</h1>
            <p>Cada alumno tiene un código QR propio y puede tener una pegatina NFC vinculada.</p>
        </div>
        @if ($withQr < $total)
            <form method="POST" action="{{ route('credentials.generate') }}">
                @csrf
                <button class="btn primary">Generar {{ $total - $withQr === 1 ? 'el QR que falta' : 'los '.($total - $withQr).' QR que faltan' }}</button>
            </form>
        @endif
    </div>

    <div class="counts-row">
        <div><b>{{ $total }}</b><span>Alumnos</span></div>
        <div><b>{{ $withQr }}</b><span><i class="sq presente"></i>Con código QR</span></div>
        <div><b>{{ $withNfc }}</b><span><i class="sq presente"></i>Con NFC vinculado</span></div>
        <div><b>{{ $total - $withNfc }}</b><span><i class="sq confirmar"></i>Sin NFC</span></div>
    </div>

    <section class="block">
        <h2 class="block-title">Imprimir por grupo</h2>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Grupo</th><th>Alumnos</th><th>Sin NFC</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                    @foreach ($groups as $g)
                        <tr>
                            <td class="serif cell-name">{{ $g->short_name }}@if ($g->label) <span class="muted mono">{{ $g->label }}</span>@endif</td>
                            <td class="mono">{{ $g->students_count }}</td>
                            <td class="mono">
                                @if ($g->without_nfc_count)
                                    <a class="link" href="{{ route('students.index', ['grado' => $g->grade, 'grupo' => $g->number]) }}">{{ $g->without_nfc_count }}</a>
                                @else
                                    <span class="muted">0</span>
                                @endif
                            </td>
                            <td class="actions-cell">
                                @if ($g->students_count)
                                    <a class="link" href="{{ route('credentials.print', ['grupo' => $g->id]) }}" target="_blank">Imprimir credenciales</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if ($ungrouped)
                        <tr>
                            <td class="serif cell-name">Sin grupo</td>
                            <td class="mono">{{ $ungrouped }}</td>
                            <td></td>
                            <td class="actions-cell"><a class="link" href="{{ route('credentials.print', ['grupo' => 'sin']) }}" target="_blank">Imprimir credenciales</a></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </section>

    <section class="block narrow-text">
        <h2 class="block-title">Vincular pegatinas NFC</h2>
        <p>Abre al alumno en <a class="link" href="{{ route('students.index') }}">Alumnos</a>, elige "Vincular NFC" y pasa la pegatina por el lector conectado a esta computadora.</p>
        <p class="muted">Usa el mismo modelo de lector y la misma configuración que en los kioscos: algunos lectores teclean el UID en hexadecimal y otros en decimal, y el valor tiene que coincidir.</p>
    </section>
</x-layouts.app>
