@php($editing = $user->exists)
<x-layouts.app :title="$editing ? $user->full_name : 'Agregar cuenta'">
    <div class="page-head">
        <div>
            <h1>{{ $editing ? $user->full_name : 'Agregar cuenta' }}</h1>
            @if ($editing)
                @php([$sq, $status] = $user->accessStatus())
                <p><span class="tag"><i class="sq {{ $sq }}"></i>{{ $status }}</span></p>
            @else
                <p>Al crearla te daremos una contraseña temporal de 24 h para entregársela.</p>
            @endif
        </div>
        <a class="btn" href="{{ route('teachers.index') }}">Volver</a>
    </div>

    @if ($temp = session('temporary_password'))
        <div class="temp-pass" role="status">
            <div>
                <h2 class="sec-title">Contraseña temporal de {{ $user->first_name }}</h2>
                <p class="mono temp-value" id="temp-value">{{ $temp }}</p>
                <p class="muted">Entrégasela en persona. Vence en 24 h y al entrar tendrá que elegir una propia. No se volverá a mostrar.</p>
            </div>
            <button type="button" class="btn" data-copy="#temp-value">Copiar</button>
        </div>
    @endif

    <div class="two-col">
        <form method="POST" action="{{ $editing ? route('teachers.update', $user) : route('teachers.store') }}" class="sec" novalidate>
            @csrf
            @if ($editing) @method('PUT') @endif
            <h2 class="sec-title">Datos</h2>
            <x-field name="first_name" label="Nombre(s)" :value="$user->first_name" required :autofocus="! $editing" />
            <x-field name="last_name_paternal" label="Apellido paterno" :value="$user->last_name_paternal" required />
            <x-field name="last_name_maternal" label="Apellido materno" :value="$user->last_name_maternal" hint="Opcional." />
            <x-field name="email" label="Correo" type="email" :value="$user->email" required autocomplete="off" hint="Con este correo entrará al sistema." />
            <div class="field">
                <span class="field-label" id="role-label">Rol</span>
                <div class="radio-row" role="radiogroup" aria-labelledby="role-label">
                    @foreach (\App\Enums\Role::cases() as $role)
                        <label class="check"><input type="radio" name="role" value="{{ $role->value }}" @checked(old('role', $user->role?->value) === $role->value)> {{ $role->label() }}</label>
                    @endforeach
                </div>
                <p class="hint">Dirección puede hacer todo; un docente ve alumnos, arma sus clases y pasa lista.</p>
                @error('role')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="form-actions">
                <button class="btn primary" type="submit">{{ $editing ? 'Guardar cambios' : 'Crear cuenta' }}</button>
            </div>
        </form>

        @if ($editing)
            <aside class="sec">
                <h2 class="sec-title">Acceso</h2>
                @error('access')<p class="error" role="alert">{{ $message }}</p>@enderror
                <dl class="facts compact-facts">
                    <dt>Último acceso</dt><dd class="mono">{{ $user->last_login_at?->timezone(\App\Models\Setting::get('timezone'))->format('d/m/Y H:i') ?? 'Nunca' }}</dd>
                    <dt>Sesiones abiertas</dt><dd class="mono">{{ $user->activeSessions() }}</dd>
                </dl>
                @if ($locked = \App\Support\LoginThrottle::accountLockedFor($user->email))
                    <div class="notice is-error" style="margin:12px 0">Bloqueada por intentos fallidos. Se desbloquea sola en {{ ceil($locked / 60) }} min.</div>
                    <form method="POST" action="{{ route('teachers.unlock', $user) }}" style="margin-bottom:14px">@csrf<button class="btn">Desbloquear ahora</button></form>
                @endif
                <div class="access-actions">
                    @can('issueTemporaryPassword', $user)
                        <form method="POST" action="{{ route('teachers.temporary-password', $user) }}">
                            @csrf
                            <button class="btn">Generar contraseña temporal</button>
                        </form>
                        <p class="hint">Para quien olvidó su contraseña. La actual deja de funcionar.</p>
                    @endcan

                    @if ($user->is(auth()->user()))
                        <p class="muted">Esta es tu cuenta. Para cambiar tu contraseña usa <a class="link" href="{{ route('password.edit') }}">Cambiar contraseña</a>.</p>
                    @else
                        <form method="POST" action="{{ route('teachers.revoke-sessions', $user) }}">
                            @csrf
                            <button class="btn">Cerrar sus sesiones</button>
                        </form>
                        <p class="hint">Sale de todos sus dispositivos. Útil si olvidó cerrar sesión en un equipo compartido.</p>

                        <form method="POST" action="{{ route('teachers.toggle-active', $user) }}">
                            @csrf @method('PATCH')
                            <button class="btn">{{ $user->is_active ? 'Suspender cuenta' : 'Reactivar cuenta' }}</button>
                        </form>
                        <p class="hint">{{ $user->is_active ? 'Sale de inmediato y no podrá entrar hasta que la reactives.' : 'Podrá volver a entrar con su contraseña.' }}</p>

                        @can('delete', $user)
                            <form method="POST" action="{{ route('teachers.destroy', $user) }}" data-confirm="¿Dar de baja a {{ $user->full_name }}? Ya no podrá entrar; su historial se conserva.">
                                @csrf @method('DELETE')
                                <button class="btn danger">Dar de baja</button>
                            </form>
                        @endcan
                    @endif
                </div>
            </aside>
        @endif
    </div>
</x-layouts.app>
