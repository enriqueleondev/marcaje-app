@props(['title' => null, 'flush' => false])
@php($user = auth()->user())
<x-layouts.base :title="$title">
    <div class="app">
        <header class="topbar">
            <a class="brand" href="{{ route('home') }}">Marcaje</a>
            <button type="button" data-nav-toggle aria-expanded="false" aria-controls="side">Menú</button>
        </header>
        <aside class="side" id="side">
            <a class="brand" href="{{ route('home') }}">Marcaje
                @if ($institution = \App\Models\Setting::institutionName())<small>{{ $institution }}</small>@endif</a>
            <nav aria-label="Principal">
                @foreach (\App\Support\Navigation::for($user) as $section => $items)
                    @if ($section !== '')<h4>{{ $section }}</h4>@endif
                    @foreach ($items as $item)
                        <a href="{{ $item['url'] }}" @if ($item['current']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endforeach
                @endforeach
            </nav>
            <div class="me">
                <div>{{ $user->full_name }}<small>{{ $user->role->label() }}</small></div>
                <div class="actions">
                    <a class="link" href="{{ route('password.edit') }}">Cambiar contraseña</a>
                    <button type="button" class="link" data-theme-toggle>Tema</button>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="link">Salir</button>
                    </form>
                </div>
            </div>
        </aside>
        <main @class(['main', 'flush' => $flush])>
            @if (session('status'))
                <p class="notice flash" role="status">{{ session('status') }}</p>
            @endif
            {{ $slot }}
        </main>
    </div>
</x-layouts.base>
