<x-layouts.app title="Pase de lista">
    <div class="page-head">
        <div>
            <h1>Pase de lista</h1>
            <p>{{ $isToday ? 'Hoy, ' : '' }}{{ $dateLabel }}.</p>
        </div>
        <form method="GET" action="{{ route('roll-calls.index') }}" class="inline-form">
            <label class="sr-only" for="fecha">Fecha</label>
            <input class="input compact mono" type="date" id="fecha" name="fecha" value="{{ $date }}" max="{{ $today }}">
            <button class="btn" type="submit">Ver día</button>
            @unless ($isToday)<a class="link" href="{{ route('roll-calls.index') }}">Hoy</a>@endunless
        </form>
    </div>

    @if ($classes->isEmpty())
        <p class="empty">
            No hay clases este día.
            <a class="link" href="{{ route('courses.index') }}">Revisa el horario de tus clases.</a>
        </p>
    @else
        <ol class="day">
            @foreach ($classes as $s)
                @php($rc = $s->roll_call)
                <li @class(['day-item', 'is-now' => $isToday && $s->window_state === 'now'])>
                    <span class="day-time mono">{{ $s->timeRange() }}</span>
                    <span class="day-name">
                        <b class="serif">{{ $s->course->title() }}</b>
                        <span class="muted">{{ collect([$s->course->room, auth()->user()->isAdmin() ? $s->course->teacher?->full_name : null])->filter()->join(', ') }}</span>
                    </span>
                    <span class="day-state">
                        @if ($isToday && $s->window_state === 'now')
                            <span class="tag"><i class="sq presente"></i>En curso</span>
                        @elseif ($isToday && $s->window_state === 'next')
                            <span class="tag"><i class="sq confirmar"></i>Próxima</span>
                        @elseif ($rc)
                            <span class="tag"><i class="sq presente"></i>{{ $rc->decided_count }} de {{ $rc->marks_count }} marcados</span>
                        @else
                            <span class="tag"><i class="sq falta"></i>Sin pase de lista</span>
                        @endif
                    </span>
                    <span class="day-action">
                        <a @class(['btn', 'primary' => $isToday && $s->window_state === 'now']) href="{{ route('roll-calls.show', [$s, $date]) }}">
                            {{ $isToday && $s->window_state === 'now' ? 'Pasar lista' : 'Ver lista' }}
                        </a>
                    </span>
                </li>
            @endforeach
        </ol>
    @endif
</x-layouts.app>
