@php($course = $this->course)
<div>
    <div class="page-head">
        <div>
            <h1>{{ $course->name }}</h1>
            <p>{{ collect([$course->group?->name, $course->room, auth()->user()->isAdmin() ? $course->teacher?->full_name : null])->filter()->join('. ') ?: 'Sin grupo' }}.</p>
        </div>
        <a class="btn" href="{{ route('courses.index') }}">Volver a clases</a>
    </div>

    @if ($notice)<p class="notice" role="status" style="margin-top:16px">{{ $notice }}</p>@endif

    <div class="two-col">
        <section>
            <div class="sec">
                <h2 class="sec-title">Alumnos de la clase <span class="mono">{{ $this->members->count() }}</span></h2>
                @if ($this->members->isEmpty())
                    <p class="empty">Aún no hay alumnos. Búscalos abajo y agrégalos.</p>
                @else
                    <ul class="roster">
                        @foreach ($this->members as $m)
                            <li wire:key="m-{{ $m->id }}">
                                <span class="serif">{{ $m->list_name }}</span>
                                <span class="mono muted">{{ $m->group?->short_name ?? 'Sin grupo' }}</span>
                                <button type="button" class="link warn" wire:click="removeStudent({{ $m->id }})"
                                        wire:confirm="¿Quitar a {{ $m->full_name }} de esta clase?">Quitar</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="sec">
                <h2 class="sec-title">Agregar alumnos</h2>
                <div class="list-head flat">
                    <label class="sr-only" for="add-search">Buscar alumnos</label>
                    <input class="input" id="add-search" type="search" wire:model.live.debounce.250ms="search" placeholder="Buscar por nombre o matrícula" autocomplete="off">
                    <div class="chips" role="group" aria-label="Grado">
                        <span class="chips-label">Grado</span>
                        @foreach ($grades as $g)
                            <button type="button" wire:click="setGrade({{ $g }})" aria-pressed="{{ $grade === $g ? 'true' : 'false' }}">{{ $g }}°</button>
                        @endforeach
                    </div>
                    <div class="chips" role="group" aria-label="Grupo">
                        <span class="chips-label">Grupo</span>
                        @foreach ($numbers as $n)
                            <button type="button" wire:click="setGroupNumber({{ $n }})" aria-pressed="{{ $groupNumber === $n ? 'true' : 'false' }}">{{ $n }}</button>
                        @endforeach
                    </div>
                </div>

                @if ($this->candidates->isNotEmpty())
                    <ul class="roster pick">
                        @foreach ($this->candidates as $c)
                            <li wire:key="c-{{ $c->id }}">
                                <label class="check">
                                    <input type="checkbox" value="{{ $c->id }}" wire:model.live="toAdd">
                                    <span class="serif">{{ $c->list_name }}</span>
                                </label>
                                <span class="mono muted">{{ $c->group?->short_name ?? 'Sin grupo' }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="form-actions">
                        <button type="button" class="btn primary" wire:click="addSelected" @disabled(! $toAdd)>
                            {{ count($toAdd) ? 'Agregar '.count($toAdd).(count($toAdd) === 1 ? ' alumno' : ' alumnos') : 'Elige alumnos' }}
                        </button>
                        <button type="button" class="btn" wire:click="addAllShown">Agregar los {{ $this->candidates->count() }} de la lista</button>
                    </div>
                @elseif ($search !== '' || $grade || $groupNumber)
                    <p class="empty">No hay más alumnos con esos filtros. Los que ya están en la clase no aparecen aquí.</p>
                @else
                    <p class="empty">Elige grado y grupo, o busca por nombre, para ver alumnos.</p>
                @endif
            </div>
        </section>

        <aside>
            <div class="sec">
                <h2 class="sec-title">Horario</h2>
                @if ($course->schedules->isEmpty())
                    <p class="muted" style="margin-bottom:12px">Sin horario todavía: no se puede pasar lista.</p>
                @else
                    <ul class="roster" style="margin-bottom:16px">
                        @foreach ($course->schedules as $s)
                            <li wire:key="s-{{ $s->id }}">
                                <span>{{ $s->dayLabel() }}</span>
                                <span class="mono">{{ $s->timeRange() }}</span>
                                <button type="button" class="link warn" wire:click="removeSchedule({{ $s->id }})" wire:confirm="¿Quitar el horario del {{ mb_strtolower($s->dayLabel()) }}?">Quitar</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="addSchedule" class="sched-form">
                    <div class="field">
                        <label for="weekday">Día</label>
                        <select class="input" id="weekday" wire:model="weekday">
                            @foreach ($days as $n => $d)<option value="{{ $n }}">{{ $d }}</option>@endforeach
                        </select>
                    </div>
                    <div class="field-row">
                        <div class="field"><label for="startsAt">Inicio</label><input class="input mono" id="startsAt" type="time" wire:model="startsAt" required></div>
                        <div class="field"><label for="endsAt">Fin</label><input class="input mono" id="endsAt" type="time" wire:model="endsAt" required></div>
                    </div>
                    @error('startsAt')<p class="error">{{ $message }}</p>@enderror
                    @error('endsAt')<p class="error">{{ $message }}</p>@enderror
                    <div class="form-actions"><button class="btn" type="submit">Agregar horario</button></div>
                </form>
            </div>

            <form class="sec" wire:submit="saveDetails">
                <h2 class="sec-title">Datos de la clase</h2>
                <div class="field"><label for="c-name">Materia</label><input class="input" id="c-name" wire:model="name" required>
                    @error('name')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="field"><label for="c-group">Grupo</label>
                    <select class="input" id="c-group" wire:model="groupId">
                        <option value="">Varios grupos o sin grupo</option>
                        @foreach ($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                    </select></div>
                <div class="field"><label for="c-room">Aula</label><input class="input" id="c-room" wire:model="room"></div>
                <label class="check"><input type="checkbox" wire:model="isActive"> Clase activa (desmárcala al terminar el ciclo)</label>
                <div class="form-actions"><button class="btn primary" type="submit">Guardar</button></div>
            </form>
        </aside>
    </div>
</div>
