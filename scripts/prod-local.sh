#!/usr/bin/env bash
# Levanta la app en modo PRODUCCIÓN en esta máquina, con MySQL en Docker.
# Uso: scripts/prod-local.sh [--datos]   (--datos borra la base y genera datos de prueba)
set -euo pipefail
cd "$(dirname "$0")/.."

ENV_FILE=.env.production
export APP_ENV=production   # Laravel carga .env.production en lugar de .env

if [ ! -f "$ENV_FILE" ]; then
    echo "Creando $ENV_FILE para el entorno local…"
    sed -e 's|^APP_URL=.*|APP_URL=http://localhost:8000|' \
        -e 's|^DB_PORT=.*|DB_PORT=3307|' \
        -e 's|^DB_PASSWORD=.*|DB_PASSWORD=marcaje-local|' \
        .env.production.example > "$ENV_FILE"
    php artisan key:generate --force --env=production >/dev/null
fi

echo "1/5 MySQL (Docker)…"
docker compose up -d --wait mysql

echo "2/5 Assets de producción (npm run build)…"
npm ci --no-audit --no-fund --silent
npm run build >/dev/null

echo "3/5 Migraciones…"
php artisan config:clear >/dev/null
php artisan migrate --force
if [ "${1:-}" = "--datos" ]; then
    php artisan marcaje:datos-prueba --yes
fi

echo "4/5 Cachés de config, rutas y vistas…"
php artisan optimize >/dev/null

echo "5/5 Servidor en http://localhost:8000 (Ctrl+C para detener)"
echo "    Para la tableta: en otra terminal, scripts/tunnel.sh"
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000 --no-reload
