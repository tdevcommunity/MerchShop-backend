#!/bin/sh
set -e

echo "Waiting for postgres..."
until php artisan migrate:status --no-interaction 2>/dev/null | grep -q "Migration"; do
  sleep 1
done

echo "Running migrations..."
php artisan migrate --force --no-interaction

echo "Seeding if empty..."
if ! php artisan tinker --execute="echo App\Models\User::count();" 2>/dev/null | grep -qE '[1-9][0-9]*'; then
  php artisan db:seed --force --no-interaction
fi

echo "Starting server..."
exec php artisan serve --host=0.0.0.0 --port=8000
