<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1A0B33">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="robots" content="noindex">
    <title>Kiosco | Marcaje</title>
    <link rel="manifest" href="{{ route('kiosk.manifest') }}">
    <link rel="icon" href="/kiosco-icon-192.png">
    @vite(['resources/css/kiosk.css', 'resources/js/kiosk.js'])
</head>
<body>
    {{-- Vincular: si la tableta no tiene token --}}
    <section class="screen pair" id="pair" hidden>
        <div class="pair-box">
            <p class="brand">Marcaje</p>
            <h1>Vincula este kiosco</h1>
            <p class="lead" id="pair-message">En el panel, entra a Kioscos, agrega uno y escanea su código con la cámara de esta tableta. También puedes escribir el código aquí.</p>
            <form id="pair-form" autocomplete="off">
                <label for="pair-token">Código de vinculación</label>
                <input id="pair-token" class="mono" placeholder="mk_…" spellcheck="false" autocapitalize="off">
                <p class="error" id="pair-error" hidden></p>
                <button type="submit">Vincular</button>
            </form>
        </div>
    </section>

    {{-- Iniciar: el navegador exige un toque para el sonido y la pantalla completa --}}
    <section class="screen start" id="start" hidden>
        <button type="button" id="start-button">
            <span class="target small"><i></i><i></i><i></i><i></i></span>
            <span class="start-title" id="start-title">Iniciar kiosco</span>
            <span class="start-sub" id="start-sub">Activa el sonido y la pantalla completa</span>
        </button>
        <p class="start-camera" id="start-camera" hidden>
            Este kiosco lee los códigos QR con la cámara. La imagen no se guarda ni se envía a ningún lado: solo se lee el código.
            Al iniciar, el navegador te pedirá permiso para usarla.
        </p>
    </section>

    {{-- Kiosco --}}
    <main class="kiosk" id="kiosk" hidden>
        <div class="greet">
            <div class="org"><b id="org-name">Marcaje</b><span id="kiosk-name"></span></div>
            <h1 id="greeting">Buenos días.</h1>
            <p class="instruction">Pasa tu credencial por el lector o muestra tu código QR.</p>
            <div class="clock"><b id="clock">--:--:--</b><span id="date"></span></div>
        </div>
        <div class="zone">
            <div class="target" id="usb-target"><i></i><i></i><i></i><i></i><span>Lector NFC<br>y QR</span></div>
            <div class="cam" id="cam" hidden>
                <div class="cam-frame">
                    <video id="cam-video" playsinline muted autoplay aria-label="Vista de la cámara"></video>
                    <span class="cam-guide" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                </div>
                <p class="cam-hint">Centra tu código QR aquí</p>
                <p class="cam-also" id="cam-also" hidden>También puedes pasar tu credencial por el lector.</p>
                <div class="cam-controls">
                    <label class="sr-only" for="cam-select">Cámara</label>
                    <select id="cam-select" hidden></select>
                    <button type="button" id="cam-flip" hidden>Cambiar cámara</button>
                </div>
            </div>
            <div class="cam-msg" id="cam-msg" role="alert" hidden></div>
            <div class="status" aria-live="polite">
                <span id="net"><i class="sq on"></i>En línea</span>
                <span id="queue">Sin pendientes</span>
                <span id="today">0 entradas hoy</span>
            </div>
        </div>
        <ol class="strip" id="strip" aria-label="Últimas entradas"></ol>
    </main>

    {{-- Confirmación a pantalla completa (1.5 s) --}}
    <div class="flash" id="flash" role="status" aria-live="assertive" hidden>
        <div class="mono-g" id="flash-initials"></div>
        <div class="flash-body">
            <p class="flash-k"><i></i><span id="flash-kind"></span></p>
            <p class="flash-name" id="flash-name"></p>
            <dl>
                <dt>Grado y grupo</dt><dd id="flash-group"></dd>
                <dt id="flash-time-label">Hora</dt><dd id="flash-time"></dd>
                <dt>Método</dt><dd id="flash-method"></dd>
            </dl>
        </div>
    </div>

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.MARCAJE_KIOSK = {
            api: @json(url('/api/kiosco')),
            sw: '/kiosco-sw.js',
        };
    </script>
</body>
</html>
