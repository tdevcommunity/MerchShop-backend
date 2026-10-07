#!/bin/sh
# Demarrage en production : migrations, caches Laravel, puis serveur.
# Jamais de seed ici (TestDataSeeder cree des comptes de demo).
set -e

cd /app

php artisan migrate --force --no-interaction
php artisan storage:link --force >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
