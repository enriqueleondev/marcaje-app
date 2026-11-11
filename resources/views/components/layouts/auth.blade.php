@props(['title' => null])
<x-layouts.base :title="$title">
    <div class="auth">
        <aside class="auth-side">
            <div class="brand">Marcaje
                @if ($institution = \App\Models\Setting::institutionName())<small>{{ $institution }}</small>@endif</div>
            <p class="motto">Cada entrada cuenta.</p>
            <div class="foot"><span>Pase de lista con credencial NFC y QR</span><span>{{ now()->year }}</span></div>
        </aside>
        <main class="auth-main">
            <button type="button" class="btn theme-toggle" data-theme-toggle>Tema</button>
            <div class="auth-form">
                {{ $slot }}
            </div>
        </main>
    </div>
</x-layouts.base>
