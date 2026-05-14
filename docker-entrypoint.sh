#!/bin/sh

set -eu

mkdir -p \
    /app/storage/logs \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/testing \
    /app/storage/framework/views \
    /app/storage/app/tmp/document-conversions \
    /app/bootstrap/cache

php artisan optimize --no-interaction

exec "$@"
