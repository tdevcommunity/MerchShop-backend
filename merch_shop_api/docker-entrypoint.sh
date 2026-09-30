#!/bin/sh
set -e

echo "Waiting for database..."
until php artisan tinker --execute="echo 'DB connected';" 2>/dev/null | grep -q "DB connected"; do
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
