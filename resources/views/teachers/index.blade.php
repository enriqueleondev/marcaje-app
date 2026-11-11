<x-layouts.app title="Docentes">
    <div class="page-head">
        <div>
            <h1>{{ $show === 'bajas' ? 'Cuentas dadas de baja' : 'Docentes y dirección' }}</h1>
            <p>Cuentas del personal que usa Marcaje.</p>
        </div>
        <div class="head-actions">
            <a class="btn" href="{{ route('teachers.index', $show === 'bajas' ? [] : ['ver' => 'bajas']) }}">{{ $show === 'bajas' ? 'Ver cuentas activas' : 'Ver bajas' }}</a>
            @if ($show !== 'bajas')
                <a class="btn" href="{{ route('teachers.import') }}">Importar CSV</a>
                <a class="btn primary" href="{{ route('teachers.create') }}">Agregar cuenta</a>
            @endif
        </div>
    </div>

    @foreach (['Docentes' => $teachers, 'Dirección' => $admins] as $title => $users)
        <section class="block">
            <h2 class="block-title">{{ $title }} <span class="mono muted">{{ $users->count() }}</span></h2>
            @if ($users->isEmpty())
                <p class="empty">{{ $show === 'bajas' ? 'Ninguna cuenta dada de baja.' : 'Aún no hay cuentas. Agrega la primera con "Agregar cuenta".' }}</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Nombre</th><th>Correo</th><th>Acceso</th><th>Último acceso</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                        <tbody>
                            @foreach ($users as $u)
                                @php([$sq, $status] = $u->accessStatus())
                                <tr>
                                    <td class="serif cell-name">{{ $u->full_name }}@if ($u->is(auth()->user())) <span class="mono muted">(tú)</span>@endif</td>
                                    <td>{{ $u->email }}</td>
                                    <td><span class="tag"><i class="sq {{ $sq }}"></i>{{ $status }}</span></td>
                                    <td class="mono muted">{{ $u->last_login_at?->timezone(\App\Models\Setting::get('timezone'))->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                                    <td class="actions-cell">
                                        @if ($u->trashed())
                                            <form method="POST" action="{{ route('teachers.restore', $u->id) }}">@csrf<button class="link">Reactivar</button></form>
                                        @else
                                            <a class="link" href="{{ route('teachers.edit', $u) }}">Editar</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endforeach
</x-layouts.app>
