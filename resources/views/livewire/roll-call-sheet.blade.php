@php($labels = collect(\App\Enums\MarkState::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
<div class="room"
     x-data="{
        sel: null, selName: '', selEntry: false,
        pick(el) { this.sel = Number(el.dataset.id); this.selName = el.dataset.name; this.selEntry = el.dataset.entry === '1' },
        seats() { return [...this.$refs.grid.querySelectorAll('.seat')] },
        cols() { return getComputedStyle(this.$refs.grid).gridTemplateColumns.split(' ').length },
        focusSel() { this.$nextTick(() => this.seats().find(s => Number(s.dataset.id) === this.sel)?.focus()) },
        mark(status) { if (this.sel !== null) $wire.setStatus(this.sel, status).then(() => this.focusSel()) },
        move(delta) { if (this.sel !== null) $wire.moveBy(this.sel, delta).then(() => this.focusSel()) },
        key(e) {
            const seat = e.target.closest('.seat'); if (!seat) return
            const list = this.seats(), i = list.indexOf(seat), c = this.cols()
            const step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: c, ArrowUp: -c }[e.key]
            if (step !== undefined) {
                e.preventDefault()
                if (e.altKey && $wire.reordering) { this.move(step); return }
                list[Math.max(0, Math.min(list.length - 1, i + step))]?.focus(); return
            }
            if (e.ctrlKey || e.metaKey || e.altKey) return
            const k = e.key.toLowerCase()
            const map = { p: 'present', r: 'late', f: 'absent' }
            if (map[k]) { e.preventDefault(); this.mark(map[k]) }
            else if (k === 'c') { e.preventDefault(); $wire.confirmAll() }
            else if (e.key === 'Backspace' || e.key === 'Delete') { e.preventDefault(); this.mark(null) }
        },
     }"
     @if ($open) wire:poll.20s="refresh" @endif>

    <div class="room-main">
        <div class="room-head">
            <div>
                <h1>{{ $course->title() }}</h1>
                <p class="sub">
                    <span>{{ ucfirst($rc->starts_at->timezone($tz)->locale('es')->isoFormat('dddd D [de] MMMM')) }}</span>
                    <span class="mono">{{ $rc->localTime('starts_at') }} a {{ $rc->localTime('ends_at') }}</span>
                    @if ($course->room)<span>{{ $course->room }}</span>@endif
                    @if (auth()->user()->isAdmin())<span>{{ $course->teacher?->full_name }}</span>@endif
                </p>
            </div>
            <div class="room-tools">
                <label class="sr-only" for="sort">Ordenar</label>
                <select id="sort" class="input compact" wire:model.live="sort" @disabled($reordering)>
                    @foreach (\App\Enums\RollCallSort::cases() as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                @if ($canEdit)
                    <button type="button" class="btn" wire:click="toggleReorder" aria-pressed="{{ $reordering ? 'true' : 'false' }}">{{ $reordering ? 'Listo' : 'Acomodar lugares' }}</button>
                @endif
            </div>
        </div>

        @if ($reordering)
            <div class="reorder-bar" role="status">
                <span>Arrastra los lugares para acomodarlos como se sientan en el aula. Con teclado: elige un lugar y usa Alt + flechas.</span>
                <button type="button" class="link" wire:click="resetSeats" wire:confirm="¿Volver al orden alfabético? Se pierde el acomodo actual.">Volver al alfabético</button>
            </div>
        @endif

        @if ($notice)<p class="notice" role="status" style="margin-top:14px">{{ $notice }}</p>@endif

        @if ($rows->isEmpty())
            <p class="empty">Esta clase no tiene alumnos. <a class="link" href="{{ route('courses.show', $course) }}">Agrégalos aquí.</a></p>
        @else
            <div class="front" aria-hidden="true">Pizarrón</div>
            {{-- La retícula se vuelve a crear al entrar o salir de "Acomodar lugares" para que wire:sort se inicialice. --}}
            <div class="seats" x-ref="grid" role="grid" aria-label="Lugares del aula" @keydown="key($event)"
                 wire:key="grid-{{ $reordering ? 'sort' : 'mark' }}" @if ($reordering) wire:sort="moveSeat" @endif>
                @foreach ($rows as $i => $r)
                    @php($s = $r['student'])
                    @php($state = $r['state'])
                    <button type="button" class="seat" role="gridcell"
                            wire:key="seat-{{ $s->id }}" @if ($reordering) wire:sort:item="{{ $s->id }}" @endif
                            data-id="{{ $s->id }}" data-name="{{ $s->first_name }} {{ $s->last_name_paternal }}" data-entry="{{ $r['entry'] ? '1' : '0' }}"
                            data-s="{{ $state->value }}"
                            :aria-selected="sel === {{ $s->id }} ? 'true' : 'false'"
                            @click="pick($el); $el.focus()" @focus="pick($el)"
                            aria-label="{{ $s->full_name }}, {{ $state->label() }}{{ $r['entry'] ? ', entró a las '.$r['entry']->scanned_at->timezone($tz)->format('H:i').($r['entry']->method === 'manual' ? ' (entrada manual)' : '') : '' }}">
                        <span class="seat-top"><span class="mono">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span><i class="sq {{ $state->value }}"></i></span>
                        <b>{{ $s->first_name }}<br>{{ $s->last_name_paternal }}</b>
                        <span class="t">
                            @if ($r['entry'])
                                {{ $r['entry']->scanned_at->timezone($tz)->format('H:i') }}
                                @if ($r['entry']->method === 'manual')
                                    <span class="manual-tag" title="{{ $r['entry']->manual_reason?->label() }}">Entrada manual</span>
                                @else
                                    {{ $r['entry']->methodLabel() }}
                                @endif
                            @else
                                Sin entrada
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <aside class="panel">
        <div class="clockline">
            @if ($open)
                <div>Lista abierta</div>
                <div class="track"><i style="width: {{ $progress }}%"></i></div>
                <div class="ends"><span>{{ $rc->localTime('starts_at') }}</span><span>Cierra {{ $rc->localTime('ends_at') }}</span></div>
            @elseif ($ended)
                <div>Lista cerrada a las {{ $rc->localTime('ends_at') }}</div>
                <p class="muted small">Ya no se puede editar.</p>
            @else
                <div>Abre a las {{ $rc->localTime('starts_at') }}</div>
                <p class="muted small">Podrás marcar cuando empiece la clase.</p>
            @endif
        </div>

        <div>
            <h3>Resumen</h3>
            <div class="counts">
                @foreach ($counts as $key => $n)
                    <div><b>{{ $n }}</b><span><i class="sq {{ $key }}"></i>{{ $labels[$key] }}</span></div>
                @endforeach
            </div>
        </div>

        @if ($canEdit)
            <button type="button" class="btn primary block" wire:click="confirmAll" @disabled(! $open || $counts['confirmar'] === 0)>
                @if ($counts['confirmar'])
                    Confirmar todas las entradas ({{ $counts['confirmar'] }})
                @elseif ($rc->confirmed_all_at)
                    Entradas confirmadas
                @else
                    Sin entradas por confirmar
                @endif
            </button>

            <div class="actionbar" x-show="sel !== null" x-cloak>
                <p><span class="muted">Lugar elegido</span> <b class="serif" x-text="selName"></b></p>
                <div class="actions" @if (! $open) aria-disabled="true" @endif>
                    <button type="button" class="act presente" @click="mark('present')" @disabled(! $open)><i class="sq presente"></i>Presente</button>
                    <button type="button" class="act retardo" @click="mark('late')" @disabled(! $open)><i class="sq retardo"></i>Retardo</button>
                    <button type="button" class="act falta" @click="mark('absent')" @disabled(! $open)>
                        <i class="sq" :class="selEntry ? 'noaula' : 'falta'"></i><span x-text="selEntry ? 'No en aula' : 'Falta'"></span>
                    </button>
                </div>
                <div class="actions-sub">
                    <button type="button" class="link" @click="mark(null)" @disabled(! $open)>Quitar marca</button>
                    @if ($reordering)
                        <button type="button" class="link" @click="move(-1)">Mover antes</button>
                        <button type="button" class="link" @click="move(1)">Mover después</button>
                    @endif
                    <button type="button" class="link" @click="sel = null">Cerrar</button>
                </div>
            </div>

            <div class="keys-help">
                <h3>Teclado</h3>
                <div class="keys">
                    <span><kbd>←</kbd> <kbd>→</kbd> <kbd>↑</kbd> <kbd>↓</kbd></span><span>Moverse entre lugares</span>
                    <kbd>P</kbd><span>Presente</span>
                    <kbd>R</kbd><span>Retardo</span>
                    <kbd>F</kbd><span>Falta o no en aula</span>
                    <kbd>C</kbd><span>Confirmar todas</span>
                    <kbd>⌫</kbd><span>Quitar marca</span>
                </div>
            </div>
        @endif

        <p class="mono muted small" style="margin-top:auto">Los cambios se guardan al momento. Las entradas del kiosco aparecen solas.</p>
    </aside>
</div>
