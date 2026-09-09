#!/bin/sh
set -e

mkdir -p /app/storage

PORT="${PORT:-8088}"

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

# The FrankenPHP image provides the executable at /usr/local/bin/frankenphp.
# Render supplies PORT; local installations keep 8088.
echo "[Docker] Starting PencariMovie Server with FrankenPHP on 0.0.0.0:${PORT}..."
exec /usr/local/bin/frankenphp php-server --listen "0.0.0.0:${PORT}" --root /app
