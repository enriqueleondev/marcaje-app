<x-layouts.app title="Mis clases">
    <div class="page-head">
        <div>
            <h1>{{ auth()->user()->isAdmin() ? 'Clases' : 'Mis clases' }}</h1>
            <p>Cada clase tiene su horario y su lista de alumnos. Solo se pasa lista durante el horario.</p>
        </div>
    </div>

    <div class="two-col">
        <section class="block">
            @if ($courses->isEmpty())
                <p class="empty">Aún no tienes clases. Crea la primera con el formulario.</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Clase</th><th>Horario</th><th>Alumnos</th>@if (auth()->user()->isAdmin())<th>Docente</th>@endif<th><span class="sr-only">Acciones</span></th></tr></thead>
                        <tbody>
                            @foreach ($courses as $c)
                                <tr @class(['is-muted' => ! $c->is_active])>
                                    <td>
                                        <span class="serif cell-name">{{ $c->name }}</span>
                                        <br><span class="muted">{{ collect([$c->group?->short_name, $c->room])->filter()->join(', ') ?: 'Sin grupo' }}@unless ($c->is_active), archivada @endunless</span>
                                    </td>
                                    <td class="mono">
                                        @forelse ($c->schedules as $s)
                                            <span class="sched">{{ mb_substr($s->dayLabel(), 0, 3) }} {{ $s->timeRange() }}</span>
                                        @empty
                                            <span class="muted">Sin horario</span>
                                        @endforelse
                                    </td>
                                    <td class="mono">{{ $c->students_count }}</td>
                                    @if (auth()->user()->isAdmin())<td>{{ $c->teacher?->full_name }}</td>@endif
                                    <td class="actions-cell"><a class="link" href="{{ route('courses.show', $c) }}">Abrir</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <aside class="sec">
            <h2 class="sec-title">Nueva clase</h2>
            <form method="POST" action="{{ route('courses.store') }}" novalidate>
                @csrf
                <x-field name="name" label="Materia" placeholder="Biología I" required />
                <div class="field">
                    <label for="group_id">Grupo</label>
                    <select class="input" id="group_id" name="group_id">
                        <option value="">Varios grupos o sin grupo</option>
                        @foreach ($groups as $g)
                            <option value="{{ $g->id }}" @selected((string) old('group_id') === (string) $g->id)>{{ $g->name }}</option>
                        @endforeach
                    </select>
                    <p class="hint">Si eliges un grupo, sus alumnos se agregan solos. Luego puedes quitar o agregar.</p>
                </div>
                <x-field name="room" label="Aula" placeholder="Laboratorio 2" hint="Opcional." />
                @if (auth()->user()->isAdmin())
                    <div class="field">
                        <label for="teacher_id">Docente</label>
                        <select class="input" id="teacher_id" name="teacher_id">
                            <option value="">Elige un docente</option>
                            @foreach ($teachers as $t)
                                <option value="{{ $t->id }}" @selected((string) old('teacher_id') === (string) $t->id)>{{ $t->full_name }}</option>
                            @endforeach
                        </select>
                        @error('teacher_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                @endif
                <div class="form-actions"><button class="btn primary" type="submit">Crear clase</button></div>
            </form>
        </aside>
    </div>
</x-layouts.app>
