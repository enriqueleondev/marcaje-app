@php($fmt = fn ($d) => \Carbon\CarbonImmutable::parse($d)->locale('es')->isoFormat('D [de] MMMM'))
<div>
    <div class="page-head">
        <div>
            <h1>Reportes</h1>
            <p>{{ $from === $to ? 'Del '.$fmt($from) : 'Del '.$fmt($from).' al '.$fmt($to) }}{{ $grade ? ', '.$grade.'° grado' : '' }}{{ $group ? ', grupo '.$group : '' }}.</p>
        </div>
        <a class="btn" href="{{ $exportUrl }}">Exportar CSV</a>
    </div>

    <div class="filters">
        <div class="chips" role="group" aria-label="Periodo">
            <span class="chips-label">Periodo</span>
            <button type="button" wire:click="preset('hoy')">Hoy</button>
            <button type="button" wire:click="preset('semana')">Esta semana</button>
            <button type="button" wire:click="preset('mes')">Este mes</button>
            <button type="button" wire:click="preset('30')">Últimos 30 días</button>
        </div>
        <div class="inline-form">
            <label class="field-label" for="desde">Desde</label>
            <input class="input compact mono" type="date" id="desde" wire:model.live="from">
            <label class="field-label" for="hasta">Hasta</label>
            <input class="input compact mono" type="date" id="hasta" wire:model.live="to">
        </div>
        <div class="chips" role="group" aria-label="Grado">
            <span class="chips-label">Grado</span>
            @foreach ($grades as $g)
                <button type="button" wire:click="setGrade({{ $g }})" aria-pressed="{{ $grade === $g ? 'true' : 'false' }}">{{ $g }}°</button>
            @endforeach
        </div>
        <div class="chips" role="group" aria-label="Grupo">
            <span class="chips-label">Grupo</span>
            @foreach ($numbers as $n)
                <button type="button" wire:click="setGroup({{ $n }})" aria-pressed="{{ $group === $n ? 'true' : 'false' }}">{{ $n }}</button>
            @endforeach
        </div>
    </div>

    <div class="counts-row six" wire:loading.class="is-loading">
        <div><b>{{ $totals['rate'] === null ? '—' : $totals['rate'].'%' }}</b><span>Asistencia a clases</span></div>
        <div><b>{{ $totals['present'] }}</b><span><i class="sq presente"></i>Presente</span></div>
        <div><b>{{ $totals['late'] }}</b><span><i class="sq retardo"></i>Retardo</span></div>
        <div><b>{{ $totals['unconfirmed'] }}</b><span><i class="sq confirmar"></i>Sin confirmar</span></div>
        <div><b>{{ $totals['not_in_class'] }}</b><span><i class="sq noaula"></i>No en aula</span></div>
        <div><b>{{ $totals['absent'] }}</b><span><i class="sq falta"></i>Falta</span></div>
    </div>
    <p class="mono muted note">
        {{ $totals['entries'] === 1 ? '1 entrada' : $totals['entries'].' entradas' }} en kiosco, {{ $totals['roll_calls'] === 1 ? '1 pase de lista' : $totals['roll_calls'].' pases de lista' }}.
        "Sin confirmar": el alumno tenía entrada y el docente no la confirmó; cuenta como asistencia.
    </p>

    <div class="tabs-bar" role="tablist" aria-label="Vista">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div wire:loading.class="is-loading">
        @if ($tab === 'alumnos')
            <div class="tab-tools">
                <label class="sr-only" for="r-buscar">Buscar alumno</label>
                <input class="input compact" id="r-buscar" type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar alumno">
                <span class="mono muted">Ordenado por asistencia, de menor a mayor.</span>
            </div>
            @if ($students->isEmpty())
                <p class="empty">No hay pases de lista en este periodo{{ $grade || $group ? ' para este grupo' : '' }}. Prueba con otras fechas.</p>
            @else
                <div class="table-wrap">
                    <table class="table report">
                        <thead><tr><th>Alumno</th><th>Grupo</th><th class="n">Clases</th><th class="n">Presente</th><th class="n">Retardo</th><th class="n">Sin conf.</th><th class="n">No en aula</th><th class="n">Falta</th><th>Asistencia</th></tr></thead>
                        <tbody>
                            @foreach ($students as $r)
                                <tr wire:key="rs-{{ $r->student->id }}">
                                    <td><a class="serif cell-name plain-link" href="{{ route('students.index', ['alumno' => $r->student->id]) }}">{{ $r->student->list_name }}</a></td>
                                    <td class="mono">{{ $r->student->group?->short_name ?? '—' }}</td>
                                    <td class="mono n">{{ $r->total }}</td>
                                    <td class="mono n">{{ $r->present }}</td>
                                    <td class="mono n">{{ $r->late }}</td>
                                    <td class="mono n">{{ $r->unconfirmed }}</td>
                                    <td class="mono n">{{ $r->not_in_class }}</td>
                                    <td class="mono n">{{ $r->absent }}</td>
                                    <td>
                                        <span class="rate @if ($r->rate !== null && $r->rate < 80) low @endif">
                                            <span class="bar"><i style="width: {{ $r->rate ?? 0 }}%"></i></span>
                                            <span class="mono">{{ $r->rate }}%</span>
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        @elseif ($tab === 'no-en-aula')
            <p class="muted tab-intro">Alumnos que registraron su entrada en el kiosco pero el docente marcó que no estaban en clase.</p>
            @if ($notInClass->isEmpty())
                <p class="empty">Nadie en este periodo. Todos los que entraron llegaron a sus clases.</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Fecha</th><th>Alumno</th><th>Grupo</th><th>Clase</th><th>Entrada</th><th>Docente</th></tr></thead>
                        <tbody>
                            @foreach ($notInClass as $m)
                                <tr>
                                    <td class="mono">{{ $m->rollCall->starts_at->timezone($tz)->format('d/m') }} {{ $m->rollCall->localTime('starts_at') }}</td>
                                    <td><a class="serif cell-name plain-link" href="{{ route('students.index', ['alumno' => $m->student->id]) }}">{{ $m->student->list_name }}</a></td>
                                    <td class="mono">{{ $m->student->group?->short_name ?? '—' }}</td>
                                    <td>{{ $m->rollCall->course->name }}</td>
                                    <td class="mono">{{ $m->entry?->scanned_at->timezone($tz)->format('H:i') }} {{ $m->entry?->methodLabel() }}</td>
                                    <td class="muted">{{ $m->rollCall->course->teacher?->full_name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        @elseif ($tab === 'manuales')
            <p class="muted tab-intro">Entradas registradas a mano porque el alumno no pudo pasar su credencial.</p>
            @if ($manual->isEmpty())
                <p class="empty">No hubo entradas manuales en este periodo.</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Fecha</th><th>Alumno</th><th>Grupo</th><th>Motivo</th><th>Registró</th></tr></thead>
                        <tbody>
                            @foreach ($manual as $e)
                                <tr>
                                    <td class="mono">{{ $e->scanned_at->timezone($tz)->format('d/m H:i') }}</td>
                                    <td><a class="serif cell-name plain-link" href="{{ route('students.index', ['alumno' => $e->student_id]) }}">{{ $e->student?->list_name }}</a></td>
                                    <td class="mono">{{ $e->student?->group?->short_name ?? '—' }}</td>
                                    <td>{{ $e->manual_reason?->label() }}{{ $e->manual_note ? ': '.$e->manual_note : '' }}</td>
                                    <td class="muted">{{ $e->creator?->full_name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        @else
            <p class="muted tab-intro">Sesiones que ya terminaron en el periodo. "Sin pase de lista": nadie abrió la lista. "Sin confirmar": entradas que el docente no confirmó.</p>
            @if ($courses->isEmpty())
                <p class="empty">No hubo clases con horario en este periodo.</p>
            @else
                <div class="table-wrap">
                    <table class="table report">
                        <thead><tr><th>Clase</th><th>Docente</th><th class="n">Sesiones</th><th class="n">Sin pase de lista</th><th class="n">Sin confirmar</th></tr></thead>
                        <tbody>
                            @foreach ($courses as $r)
                                <tr>
                                    <td><span class="serif cell-name">{{ $r->course->name }}</span>@if ($r->course->group) <span class="mono muted">{{ $r->course->group->short_name }}</span>@endif</td>
                                    <td>{{ $r->course->teacher?->full_name }}</td>
                                    <td class="mono n">{{ $r->expected }}</td>
                                    <td class="mono n">@if ($r->missing)<span class="tag"><i class="sq falta"></i>{{ $r->missing }}</span>@else <span class="muted">0</span> @endif</td>
                                    <td class="mono n">@if ($r->unconfirmed)<span class="tag"><i class="sq confirmar"></i>{{ $r->unconfirmed }}</span>@else <span class="muted">0</span> @endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
</div>
