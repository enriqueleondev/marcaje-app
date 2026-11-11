<x-layouts.app title="Inicio">
    <div class="page-head">
        <div>
            <h1>Hola, {{ $user->first_name }}.</h1>
            <p>{{ collect([$user->role->label(), \App\Models\Setting::institutionName()])->filter()->join(' en ') }}.</p>
        </div>
    </div>

    <dl class="facts" style="margin-top:8px">
        <dt>Nombre</dt><dd>{{ $user->full_name }}</dd>
        <dt>Correo</dt><dd>{{ $user->email }}</dd>
        <dt>Rol</dt><dd>{{ $user->role->label() }}</dd>
    </dl>
</x-layouts.app>
