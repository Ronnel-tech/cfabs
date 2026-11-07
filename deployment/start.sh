#!/bin/sh
set -eu
export PORT="${PORT:-10000}"
case "$PORT" in ''|*[!0-9]*) echo 'PORT must be an integer.' >&2; exit 1;; esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then echo 'PORT is outside the valid range.' >&2; exit 1; fi
php /var/www/html/deployment/check-env.php
mkdir -p /var/www/html/runtime/cache /var/www/html/runtime/logs /var/www/html/runtime/session
chown -R www-data:www-data /var/www/html/runtime
exec apache2-foreground
