#!/bin/bash
# Wait for PostgreSQL to be ready, then run migrations and seed data.
# This ensures `docker compose up` is all an integrating team needs to run.

set -e

echo "Waiting for PostgreSQL..."
until php -d display_errors=0 -r "@new PDO('pgsql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 1
done
echo "PostgreSQL is ready."

echo "Running migrations and seeding..."
# Retry loop: all services share one DB and race to create tables.
# If another service is migrating simultaneously, we wait and retry.
for attempt in 1 2 3 4 5; do
    if php artisan migrate --seed --force 2>&1; then
        break
    fi
    echo "Migration attempt $attempt failed (likely race condition), retrying in 3s..."
    sleep 3
done

echo "Caching configuration..."
php artisan config:cache

echo "Starting Enrolment Service on port 8003..."
exec php artisan serve --host=0.0.0.0 --port=8003
