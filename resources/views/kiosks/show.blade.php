@php([$sq, $status] = $kiosk->status())
<x-layouts.app :title="$kiosk->name">
    <div class="page-head">
        <div>
            <h1>{{ $kiosk->name }}</h1>
            <p><span class="tag"><i class="sq {{ $sq }}"></i>{{ $status }}</span></p>
        </div>
        <a class="btn" href="{{ route('kiosks.index') }}">Volver a kioscos</a>
    </div>

    @if ($token)
        <section class="pairing" role="status">
            <div class="pairing-qr">{!! \App\Support\QrImage::svg($pairingUrl) !!}</div>
            <div class="pairing-steps">
                <h2 class="serif">Vincula la tableta</h2>
                <ol class="steps">
                    <li>En la tableta, abre la cámara y escanea este código. Se abrirá el kiosco ya vinculado.</li>
                    <li>Toca "Iniciar kiosco" para activar el sonido y la pantalla completa.</li>
                    <li>Para instalarlo como app: menú del navegador › "Agregar a la pantalla principal".</li>
                </ol>
                <p class="muted">Si no puedes escanear, abre <span class="mono">{{ route('kiosk.show') }}</span> en la tableta y escribe este código:</p>
                <p class="mono token-value" id="token-value">{{ $token }}</p>
                <p class="muted">Este código solo se muestra ahora. Si lo pierdes, genera uno nuevo.</p>
                <button type="button" class="btn" data-copy="#token-value">Copiar</button>
            </div>
        </section>
    @endif

    <div class="two-col">
        <form method="POST" action="{{ route('kiosks.update', $kiosk) }}" class="sec" novalidate>
            @csrf @method('PATCH')
            <h2 class="sec-title">Datos</h2>
            <x-field name="name" label="Nombre" :value="$kiosk->name" required />
            <x-field name="location" label="Ubicación" :value="$kiosk->location" hint="Opcional." />
            <div class="form-actions"><button class="btn primary" type="submit">Guardar</button></div>
        </form>

        <aside class="sec">
            <h2 class="sec-title">Vinculación</h2>
            <div class="access-actions">
                <form method="POST" action="{{ route('kiosks.regenerate', $kiosk) }}"
                      data-confirm="¿Generar un código nuevo? La tableta actual se desvinculará y habrá que vincularla otra vez.">
                    @csrf
                    <button class="btn">{{ $kiosk->isActive() ? 'Vincular otra vez' : 'Volver a vincular' }}</button>
                </form>
                <p class="hint">Genera un código nuevo. Úsalo si cambias de tableta o perdiste el código.</p>
                @if ($kiosk->isActive())
                    <form method="POST" action="{{ route('kiosks.revoke', $kiosk) }}"
                          data-confirm="¿Desvincular {{ $kiosk->name }}? Dejará de registrar entradas de inmediato.">
                        @csrf
                        <button class="btn danger">Desvincular</button>
                    </form>
                    <p class="hint">Para una tableta perdida o que ya no se usa. Las lecturas que tenga sin enviar se pierden.</p>
                @endif
            </div>
        </aside>
    </div>
</x-layouts.app>
