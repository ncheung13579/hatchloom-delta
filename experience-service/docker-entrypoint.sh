#!/bin/bash
# Wait for PostgreSQL to be ready, then run migrations and seed data.
# This ensures `docker compose up` is all an integrating team needs to run.

set -e

echo "Waiting for PostgreSQL..."
until php -r "new PDO('pgsql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 1
done
echo "PostgreSQL is ready."

echo "Running migrations and seeding..."
php artisan migrate --seed --force 2>/dev/null || true

echo "Starting Experience Service on port 8002..."
exec php artisan serve --host=0.0.0.0 --port=8002
