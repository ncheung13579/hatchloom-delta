#!/bin/bash
# Wait for PostgreSQL to be ready, then run migrations and seed data.
# This ensures `docker compose up` is all an integrating team needs to run.

set -e

echo "Waiting for PostgreSQL..."
until php -d display_errors=0 -r "@new PDO('pgsql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 1
done
echo "PostgreSQL is ready."

echo "Running migrations..."
# Retry loop: all services share one DB and race to create tables.
# If another service is migrating simultaneously, we wait and retry.
for attempt in 1 2 3 4 5; do
    if php artisan migrate --force 2>&1; then
        break
    fi
    echo "Migration attempt $attempt failed (likely race condition), retrying in 3s..."
    sleep 3
done

# Seed separately — `migrate --seed` silently skips the seeder when
# another service already applied all shared migrations ("Nothing to migrate").
echo "Seeding database..."
php artisan db:seed --force 2>&1 || true

echo "Caching configuration..."
php artisan config:cache

echo "Starting Experience Service on port 8002..."
exec php artisan serve --host=0.0.0.0 --port=8002
