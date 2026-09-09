#!/bin/sh
set -e

mkdir -p /app/storage

PORT="${PORT:-8088}"

# Render terminates TLS at its edge and forwards plain HTTP to the container.
# Tell FrankenPHP/Caddy to listen on Render's assigned HTTP port and do not
# enable automatic HTTPS inside the container.
export SERVER_NAME="http://:${PORT}"

# Pre-spawn MadelineProto IPC workers
(
    sleep 2
    echo "[Docker] Warming up IPC workers..."
    if [ -x "/app/bin/php" ]; then
        /app/bin/php /app/warmup-ipc.php || true
    elif command -v php >/dev/null 2>&1; then
        php /app/warmup-ipc.php || true
    fi
) &

# Lightweight health endpoint used by Render.
cat > /app/healthz.php <<'PHP'
<?php
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "ok\n";
PHP

echo "[Docker] Starting PencariMovie Server with FrankenPHP on 0.0.0.0:${PORT}..."

# Use the standard FrankenPHP image startup command supplied by Docker.
exec "$@"
