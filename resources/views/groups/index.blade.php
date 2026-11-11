<x-layouts.app title="Grupos">
    <div class="page-head">
        <div>
            <h1>Grupos</h1>
            <p>{{ count($grades) }} grados con {{ count($numbers) }} grupos cada uno.</p>
        </div>
        @if ($missing)
            <form method="POST" action="{{ route('groups.defaults') }}">
                @csrf
                <button class="btn primary">Crear {{ $missing === 1 ? 'el grupo que falta' : "los {$missing} grupos que faltan" }}</button>
            </form>
        @endif
    </div>

    @error('group')<p class="error" role="alert" style="margin-top:16px">{{ $message }}</p>@enderror

    @forelse ($byGrade as $grade => $groups)
        <section class="block">
            <h2 class="block-title">{{ $grade }}° grado</h2>
            <table class="table">
                <thead><tr><th>Grupo</th><th>Alumnos</th><th>Etiqueta</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                    @foreach ($groups as $group)
                        <tr>
                            <td class="serif cell-name">{{ $group->short_name }}</td>
                            <td class="mono">
                                <a class="link" href="{{ route('students.index', ['grado' => $group->grade, 'grupo' => $group->number]) }}">{{ $group->students_count }}</a>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('groups.update', $group) }}" class="inline-form">
                                    @csrf @method('PATCH')
                                    <label class="sr-only" for="label-{{ $group->id }}">Etiqueta del grupo {{ $group->short_name }}</label>
                                    <input class="input compact" id="label-{{ $group->id }}" name="label" value="{{ $group->label }}" placeholder="Sin etiqueta" maxlength="60">
                                    <button class="btn">Guardar</button>
                                </form>
                            </td>
                            <td class="actions-cell">
                                @if ($group->students_count === 0)
                                    <form method="POST" action="{{ route('groups.destroy', $group) }}" data-confirm="¿Eliminar el grupo {{ $group->short_name }}?">
                                        @csrf @method('DELETE')
                                        <button class="link warn">Eliminar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @empty
        <p class="empty">Aún no hay grupos. Crea los grupos de la escuela con el botón de arriba.</p>
    @endforelse
</x-layouts.app>
