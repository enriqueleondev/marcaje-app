<div>
    <div class="page-head">
        <div>
            <h1>Entrada manual</h1>
            <p>Para el alumno que no pudo pasar su credencial en el kiosco. Queda registrado quién la hizo y por qué.</p>
        </div>
    </div>

    @if ($notice)
        <p @class(['notice', 'is-error' => $noticeIsError]) role="status" style="margin-top:16px">{{ $notice }}</p>
    @endif

    <div class="two-col">
        <form class="sec" wire:submit="save" novalidate>
            <div class="field">
                <span class="field-label" id="student-label">Alumno</span>
                @if ($s = $this->student)
                    <div class="picked">
                        <div>
                            <b class="serif">{{ $s->full_name }}</b>
                            <span class="mono muted">{{ $s->group?->short_name ?? 'Sin grupo' }}, {{ $s->enrollment_number }}</span>
                        </div>
                        <button type="button" class="link" wire:click="clearStudent">Cambiar</button>
                    </div>
                    @if ($existing)
                        <p class="hint">Ya tiene entrada hoy a las {{ $existing->scanned_at->timezone($tz)->format('H:i') }} ({{ $existing->methodLabel() }}).</p>
                    @endif
                @else
                    <label class="sr-only" for="m-search">Buscar alumno por nombre o matrícula</label>
                    <input class="input" id="m-search" type="search" wire:model.live.debounce.250ms="search" placeholder="Nombre o matrícula" autocomplete="off" autofocus aria-describedby="student-label">
                    @if ($this->results->isNotEmpty())
                        <ul class="roster pick" role="listbox" aria-label="Resultados">
                            @foreach ($this->results as $r)
                                <li wire:key="r-{{ $r->id }}">
                                    <button type="button" class="pick-btn" wire:click="pick({{ $r->id }})">
                                        <span class="serif">{{ $r->list_name }}</span>
                                        <span class="mono muted">{{ $r->group?->short_name ?? 'Sin grupo' }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($search)) >= 2)
                        <p class="hint">Ningún alumno coincide{{ auth()->user()->isAdmin() ? '' : ' entre los alumnos de tus clases' }}.</p>
                    @endif
                @endif
                @error('studentId')<p class="error">{{ $message }}</p>@enderror
            </div>

            <fieldset class="field">
                <legend class="field-label">Motivo</legend>
                <div class="radio-col">
                    @foreach ($reasons as $r)
                        <label class="check"><input type="radio" name="reason" value="{{ $r->value }}" wire:model.live="reason"> {{ $r->label() }}</label>
                    @endforeach
                </div>
                @error('reason')<p class="error">{{ $message }}</p>@enderror
            </fieldset>

            @if ($reason === 'other')
                <div class="field">
                    <label for="m-note">¿Cuál?</label>
                    <input class="input" id="m-note" wire:model="note" maxlength="200" placeholder="Ej. llegó con su tutor por cita médica">
                    @error('note')<p class="error">{{ $message }}</p>@enderror
                </div>
            @endif

            <div class="field">
                <label for="m-time">Hora de entrada (hoy)</label>
                <input class="input compact mono" id="m-time" type="time" wire:model="time" required>
                @error('time')<p class="error">{{ $message }}</p>@enderror
            </div>

            <div class="form-actions"><button class="btn primary" type="submit">Registrar entrada</button></div>
        </form>

        <aside class="sec">
            <h2 class="sec-title">Entradas manuales de hoy <span class="mono">{{ $today->count() }}</span></h2>
            @if ($today->isEmpty())
                <p class="muted">Ninguna todavía.</p>
            @else
                <ul class="roster">
                    @foreach ($today as $e)
                        <li class="manual-row">
                            <span>
                                <span class="serif">{{ $e->student?->list_name }}</span><br>
                                <span class="muted small">{{ $e->manual_reason?->label() }}{{ $e->manual_note ? ': '.$e->manual_note : '' }}. Registró {{ $e->creator?->full_name }}.</span>
                            </span>
                            <span class="mono">{{ $e->scanned_at->timezone($tz)->format('H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </aside>
    </div>
</div>
