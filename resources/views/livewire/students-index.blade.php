<div class="md">
    <div class="list">
        <div class="list-head">
            <div class="row">
                <h1>{{ $trashed ? 'Bajas' : 'Alumnos' }}</h1>
                @can('create', App\Models\Student::class)
                    <span class="head-actions">
                        <a class="btn" href="{{ route('students.import') }}">Importar CSV</a>
                        <a class="btn primary" href="{{ route('students.create') }}">Agregar</a>
                    </span>
                @endcan
            </div>
            <label class="sr-only" for="buscar">Buscar alumnos</label>
            <input class="input" id="buscar" type="search" wire:model.live.debounce.250ms="search" placeholder="Buscar por nombre o matrícula" autocomplete="off">
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
                <button type="button" wire:click="setGroup(0)" aria-pressed="{{ $group === 0 ? 'true' : 'false' }}">Sin grupo</button>
                @can('admin')
                    <button type="button" wire:click="toggleTrashed" aria-pressed="{{ $trashed ? 'true' : 'false' }}">Bajas</button>
                @endcan
            </div>
            <p class="mono muted" aria-live="polite">{{ $students->total() === 1 ? '1 alumno' : $students->total().' alumnos' }}</p>
        </div>

        <div class="items" wire:loading.class="is-loading">
            @forelse ($students as $s)
                <button type="button" class="item" wire:key="s-{{ $s->id }}" wire:click="select({{ $s->id }})" aria-current="{{ $selected === $s->id ? 'true' : 'false' }}">
                    <b>{{ $s->list_name }}</b>
                    <span class="mono">{{ $s->group?->short_name ?? 'Sin grupo' }}</span>
                    <span class="mono">{{ $s->enrollment_number }}</span>
                </button>
            @empty
                <div class="empty">
                    @if ($search !== '' || $grade || $group !== null)
                        Ningún alumno coincide con la búsqueda. Prueba con otro nombre o quita los filtros.
                    @elseif ($trashed)
                        No hay alumnos dados de baja.
                    @else
                        Aún no hay alumnos.
                        @can('create', App\Models\Student::class) Agrégalos uno por uno o impórtalos desde un CSV. @endcan
                    @endif
                </div>
            @endforelse
            @if ($students->hasPages())
                <div class="pager">
                    <button type="button" class="btn" wire:click="previousPage" @disabled($students->onFirstPage())>Anterior</button>
                    <span class="mono muted">Página {{ $students->currentPage() }} de {{ $students->lastPage() }}</span>
                    <button type="button" class="btn" wire:click="nextPage" @disabled(! $students->hasMorePages())>Siguiente</button>
                </div>
            @endif
        </div>
    </div>

    <section class="insp" aria-label="Detalle del alumno">
        @if ($notice)
            <p class="notice" role="status">{{ $notice }}</p>
        @endif

        @if ($student = $this->student)
            <div class="insp-head">
                <div>
                    <h2>{{ $student->first_name }}<br>{{ trim($student->last_name_paternal.' '.$student->last_name_maternal) }}</h2>
                    <p>{{ $student->group?->name ?? 'Sin grupo' }}. Matrícula <span class="mono">{{ $student->enrollment_number }}</span>.</p>
                </div>
                <div class="head-actions">
                    @if ($student->trashed())
                        @can('restore', $student)
                            <button type="button" class="btn" wire:click="restore">Reactivar</button>
                        @endcan
                    @else
                        @can('update', $student)
                            <a class="btn" href="{{ route('students.edit', $student) }}">Editar datos</a>
                        @endcan
                        @can('delete', $student)
                            <button type="button" class="btn danger" wire:click="delete"
                                    wire:confirm="¿Dar de baja a {{ $student->full_name }}? Su historial de asistencia se conserva y puedes reactivarlo después.">Dar de baja</button>
                        @endcan
                    @endif
                </div>
            </div>

            <dl class="facts">
                <dt>Nombre(s)</dt><dd>{{ $student->first_name }}</dd>
                <dt>Apellido paterno</dt><dd>{{ $student->last_name_paternal }}</dd>
                <dt>Apellido materno</dt><dd>{{ $student->last_name_maternal ?? '—' }}</dd>
                <dt>Matrícula</dt><dd class="mono">{{ $student->enrollment_number }}</dd>
                @if ($student->trashed())
                    <dt>Baja</dt><dd>{{ $student->deleted_at->timezone(\App\Models\Setting::get('timezone'))->format('d/m/Y') }}</dd>
                @endif
            </dl>

            @if (! $student->trashed() && auth()->user()->can('update', $student))
                @php($qr = $student->activeQr)
                @php($nfc = $student->activeNfc)
                <div class="cred">
                    <div class="qr-box">
                        @if ($qr)
                            {!! \App\Support\QrImage::svg($qr->value) !!}
                        @else
                            <span class="mono muted">Sin QR activo</span>
                        @endif
                    </div>
                    <div class="cred-rows">
                        <div class="cred-row">
                            <span class="k">Código QR</span>
                            @if ($qr)
                                <span class="v big mono">{{ $qr->display }}</span>
                                <span class="a">
                                    <button type="button" class="link" wire:click="regenerateQr"
                                            wire:confirm="¿Generar un código nuevo? El actual dejará de funcionar y habrá que imprimir la credencial otra vez.">Regenerar</button>
                                    <button type="button" class="link warn" wire:click="revokeQr"
                                            wire:confirm="¿Revocar el código QR? El alumno no podrá registrar su entrada con QR hasta que generes otro.">Revocar</button>
                                </span>
                            @else
                                <span class="v muted">Revocado</span>
                                <span class="a"><button type="button" class="link" wire:click="issueQr">Generar QR</button></span>
                            @endif
                        </div>
                        @if ($qr)
                            <div class="cred-row">
                                <span class="k">Credencial</span>
                                <span class="v links">
                                    <a class="link" href="{{ route('credentials.print', ['alumno' => $student->id]) }}" target="_blank">Imprimir</a>
                                    <a class="link" href="{{ route('students.qr.png', $student) }}">Descargar PNG</a>
                                    <a class="link" href="{{ route('students.qr.svg', $student) }}">Descargar SVG</a>
                                </span>
                            </div>
                        @endif
                        <div class="cred-row">
                            <span class="k">NFC</span>
                            @if ($linkingNfc)
                                <form class="v nfc-form" wire:submit="linkNfc">
                                    <label class="sr-only" for="nfcInput">UID de la pegatina NFC</label>
                                    <input class="input compact mono" id="nfcInput" wire:model="nfcInput" x-init="$el.focus()"
                                           placeholder="Pasa la pegatina por el lector" autocomplete="off"
                                           @error('nfcInput') aria-invalid="true" aria-describedby="nfc-error" @enderror>
                                    <button class="btn" type="submit">Vincular</button>
                                    <button class="link" type="button" wire:click="cancelNfc">Cancelar</button>
                                    @error('nfcInput')<p class="error" id="nfc-error">{{ $message }}</p>@enderror
                                </form>
                            @elseif ($nfc)
                                <span class="v mono"><i class="sq presente"></i>{{ $nfc->value }}</span>
                                <span class="a">
                                    <button type="button" class="link" wire:click="startNfc">Reemplazar</button>
                                    <button type="button" class="link warn" wire:click="revokeNfc"
                                            wire:confirm="¿Desvincular la pegatina NFC de {{ $student->first_name }}?">Quitar</button>
                                </span>
                            @else
                                <span class="v"><i class="sq confirmar"></i><span class="muted">Sin vincular</span></span>
                                <span class="a"><button type="button" class="link" wire:click="startNfc">Vincular NFC</button></span>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($student->credentials->isNotEmpty())
                    <details class="sec history">
                        <summary class="sec-title">Credenciales anteriores</summary>
                        <table class="table">
                            <tbody>
                                @foreach ($student->credentials as $old)
                                    <tr>
                                        <td class="mono">{{ $old->type->label() }}</td>
                                        <td class="mono">{{ $old->display }}</td>
                                        <td class="mono muted">Revocada {{ $old->revoked_at->timezone(\App\Models\Setting::get('timezone'))->format('d/m/Y H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </details>
                @endif
            @endif

            @if ($days)
                @php($entered = collect($days)->where('entered', true)->count())
                <div class="sec">
                    <h3 class="sec-title" style="justify-content:space-between">
                        <span>Últimos {{ count($days) }} días de clases</span>
                        <span>Entró {{ $entered }} de {{ count($days) }}</span>
                    </h3>
                    <div class="days" role="img" aria-label="Entró {{ $entered }} de los últimos {{ count($days) }} días de clases">
                        @foreach ($days as $d)
                            <i class="{{ $d['entered'] ? 'p' : 'f' }}" title="{{ \Carbon\CarbonImmutable::parse($d['date'])->locale('es')->isoFormat('ddd D MMM') }}: {{ $d['entered'] ? 'entró' : 'sin entrada' }}"></i>
                        @endforeach
                    </div>
                    @can('admin')
                        <p style="margin-top:12px"><a class="link" href="{{ route('reports.index', ['buscar' => $student->enrollment_number, 'desde' => now(\App\Models\Setting::get('timezone'))->subDays(29)->toDateString()]) }}">Ver su asistencia a clases</a></p>
                    @endcan
                </div>
            @endif

            @unless ($student->trashed())
                @can('changeGroup', $student)
                    <form class="sec" wire:submit="saveGroup">
                        <h3 class="sec-title"><label for="groupId">Grado y grupo</label></h3>
                        <div class="inline-form">
                            <select class="input compact" id="groupId" wire:model="groupId">
                                <option value="">Sin grupo</option>
                                @foreach ($this->groups as $g)
                                    <option value="{{ $g->id }}">{{ $g->name }}{{ $g->label ? ' ('.$g->label.')' : '' }}</option>
                                @endforeach
                            </select>
                            <button class="btn" type="submit">Cambiar grupo</button>
                        </div>
                        @error('groupId')<p class="error">{{ $message }}</p>@enderror
                    </form>
                @endcan
            @endunless
        @else
            <div class="insp-empty">
                <p class="serif">Elige un alumno de la lista para ver sus datos.</p>
            </div>
        @endif
    </section>
</div>
