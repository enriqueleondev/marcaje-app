@php($tz = \App\Models\Setting::get('timezone'))
<x-layouts.app title="Kioscos">
    <div class="page-head">
        <div>
            <h1>Kioscos</h1>
            <p>Tabletas donde los alumnos registran su entrada. {{ $todayCount === 1 ? '1 entrada hoy.' : $todayCount.' entradas hoy.' }}</p>
        </div>
    </div>

    <div class="two-col">
        <div>
            <section class="block">
                <h2 class="block-title">Tabletas <span class="mono muted">{{ $kiosks->count() }}</span></h2>
                @if ($kiosks->isEmpty())
                    <p class="empty">Aún no hay kioscos. Agrega uno y vincula la tableta escaneando el código que aparecerá.</p>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Kiosco</th><th>Estado</th><th>Hoy</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                            <tbody>
                                @foreach ($kiosks as $k)
                                    @php([$sq, $status] = $k->status())
                                    <tr>
                                        <td><span class="serif cell-name">{{ $k->name }}</span>@if ($k->location)<br><span class="muted">{{ $k->location }}</span>@endif</td>
                                        <td><span class="tag"><i class="sq {{ $sq }}"></i>{{ $status }}</span></td>
                                        <td class="mono">{{ $k->today_count }}</td>
                                        <td class="actions-cell"><a class="link" href="{{ route('kiosks.show', $k) }}">Administrar</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="block">
                <h2 class="block-title">Últimas entradas</h2>
                @if ($recent->isEmpty())
                    <p class="empty">Todavía no hay entradas registradas.</p>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>Alumno</th><th>Grupo</th><th>Hora</th><th>Método</th><th>Kiosco</th></tr></thead>
                            <tbody>
                                @foreach ($recent as $e)
                                    <tr>
                                        <td class="serif cell-name">{{ $e->student?->list_name }}</td>
                                        <td class="mono">{{ $e->student?->group?->short_name ?? '—' }}</td>
                                        <td class="mono">{{ $e->scanned_at->timezone($tz)->isToday() ? $e->scanned_at->timezone($tz)->format('H:i:s') : $e->scanned_at->timezone($tz)->format('d/m H:i') }}</td>
                                        <td class="mono">{{ $e->methodLabel() }}</td>
                                        <td class="muted">{{ $e->kiosk?->name ?? 'Manual' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <aside class="sec">
            <h2 class="sec-title">Agregar kiosco</h2>
            <form method="POST" action="{{ route('kiosks.store') }}" novalidate>
                @csrf
                <x-field name="name" label="Nombre" placeholder="Kiosco 1" required />
                <x-field name="location" label="Ubicación" placeholder="Acceso principal" hint="Opcional." />
                <div class="form-actions"><button class="btn primary" type="submit">Agregar y vincular</button></div>
            </form>
        </aside>
    </div>
</x-layouts.app>
