<x-dynamic-component :component="$forced ? 'layouts.auth' : 'layouts.app'" title="Cambiar contraseña">
    @unless ($forced)
        <div class="page-head"><div><h1>Cambiar contraseña</h1></div></div>
    @endunless

    <div class="{{ $forced ? '' : 'narrow stack' }}" @unless($forced) style="margin-top:24px" @endunless>
        @if ($forced)
            <h1>Elige tu contraseña</h1>
            <p>Entraste con una contraseña temporal. Antes de continuar, elige una propia.</p>
        @endif

        <form method="POST" action="{{ route('password.update') }}" novalidate>
            @csrf
            @method('PUT')
            <div class="field">
                <label for="current_password">{{ $forced ? 'Contraseña temporal' : 'Contraseña actual' }}</label>
                <input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required
                       @error('current_password') aria-invalid="true" aria-describedby="current-error" @enderror>
                @error('current_password')<p class="error" id="current-error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="password">Nueva contraseña</label>
                <input class="input" id="password" name="password" type="password" autocomplete="new-password" required
                       aria-describedby="password-hint @error('password') password-error @enderror"
                       @error('password') aria-invalid="true" @enderror>
                <p class="hint" id="password-hint">Al menos 10 caracteres, con letras y números.</p>
                @error('password')<p class="error" id="password-error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="password_confirmation">Repite la nueva contraseña</label>
                <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            </div>
            <div style="margin-top:24px">
                <button type="submit" class="btn primary {{ $forced ? 'block' : '' }}">Guardar contraseña</button>
            </div>
        </form>

        @if ($forced)
            <form method="POST" action="{{ route('logout') }}" class="after">
                @csrf
                <button type="submit" class="link">Salir sin cambiarla</button>
            </form>
        @endif
    </div>
</x-dynamic-component>
