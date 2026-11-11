@php($editing = $student->exists)
<x-layouts.app :title="$editing ? 'Editar alumno' : 'Agregar alumno'">
    <div class="page-head">
        <div>
            <h1>{{ $editing ? $student->full_name : 'Agregar alumno' }}</h1>
            <p>{{ $editing ? 'Edita los datos del alumno.' : 'Los alumnos no tienen cuenta: solo los manejan docentes y dirección.' }}</p>
        </div>
    </div>

    <form method="POST" action="{{ $editing ? route('students.update', $student) : route('students.store') }}" class="form-narrow" novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-field name="first_name" label="Nombre(s)" :value="$student->first_name" required autofocus />
        <x-field name="last_name_paternal" label="Apellido paterno" :value="$student->last_name_paternal" required />
        <x-field name="last_name_maternal" label="Apellido materno" :value="$student->last_name_maternal" hint="Opcional." />
        <x-field name="enrollment_number" label="Matrícula" :value="$student->enrollment_number" required class="mono" />

        <div class="field">
            <label for="group_id">Grado y grupo</label>
            <select class="input" id="group_id" name="group_id">
                <option value="">Sin grupo</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected((string) old('group_id', $student->group_id) === (string) $g->id)>{{ $g->name }}{{ $g->label ? ' ('.$g->label.')' : '' }}</option>
                @endforeach
            </select>
            @if ($groups->isEmpty())
                <p class="hint">Aún no hay grupos. @can('admin')<a class="link" href="{{ route('groups.index') }}">Créalos aquí.</a>@endcan</p>
            @endif
            @error('group_id')<p class="error">{{ $message }}</p>@enderror
        </div>

        <div class="form-actions">
            <button class="btn primary" type="submit">{{ $editing ? 'Guardar cambios' : 'Agregar alumno' }}</button>
            <a class="btn" href="{{ route('students.index', $editing ? ['alumno' => $student->id] : []) }}">Cancelar</a>
        </div>
    </form>
</x-layouts.app>
