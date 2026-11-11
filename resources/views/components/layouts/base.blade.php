@props(['title' => null])
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' | ' : '' }}Marcaje</title>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        try { const t = localStorage.getItem('marcaje.theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    {{ $slot }}
</body>
</html>
