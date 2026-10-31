#!/bin/sh
set -e

until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
    echo "Esperando a MySQL..."
    sleep 2
done

# The key lives in the app-storage volume so it survives down/up and is shared with reverb.
if [ -z "$APP_KEY" ]; then
    KEY_FILE=storage/app/.app_key
    [ -s "$KEY_FILE" ] || php artisan key:generate --show > "$KEY_FILE"
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force
    php artisan app:seed-if-empty
fi

exec "$@"
