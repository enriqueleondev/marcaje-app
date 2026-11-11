#!/usr/bin/env bash
# Túnel HTTPS temporal a la app local (para abrirla en la Galaxy Tab y probar el kiosco).
# La URL cambia cada vez. Mientras esté abierto, cualquiera con la URL puede ver tu copia
# local: úsalo solo con datos de prueba y ciérralo (Ctrl+C) al terminar.
set -euo pipefail
BIN="${HOME}/.local/bin/cloudflared"
if [ ! -x "$BIN" ]; then
    echo "Descargando cloudflared (binario oficial de Cloudflare)…"
    mkdir -p "$(dirname "$BIN")"
    curl -fsSL -o "$BIN" https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64
    chmod +x "$BIN"
fi
echo "Abriendo túnel a http://127.0.0.1:8000 … la URL https://…trycloudflare.com aparece abajo."
exec "$BIN" tunnel --no-autoupdate --url http://127.0.0.1:8000
