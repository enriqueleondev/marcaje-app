<x-layouts.auth title="Entrar">
    <h1>Entrar</h1>
    <p>Para docentes y dirección.</p>

    <form method="POST" action="{{ route('login.store') }}" novalidate>
        @csrf
        <div class="field">
            <label for="email">Correo</label>
            <input class="input" id="email" name="email" type="email" value="{{ old('email') }}"
                   autocomplete="username" required autofocus
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')<p class="error" id="email-error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
            <label for="password">Contraseña</label>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password" required
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            @error('password')<p class="error" id="password-error">{{ $message }}</p>@enderror
        </div>
        <div class="row">
            <label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Mantener la sesión abierta</label>
        </div>
        <button type="submit" class="btn primary block">Entrar</button>
    </form>

    <p class="after">¿Olvidaste tu contraseña? Pide a dirección una contraseña temporal.</p>
</x-layouts.auth>
