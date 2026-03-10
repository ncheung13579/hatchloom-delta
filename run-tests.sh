#!/usr/bin/env bash
# Run tests against PostgreSQL using Docker Compose.
#
# Usage:
#   ./run-tests.sh              # run all services sequentially
#   ./run-tests.sh dashboard    # run one service
#   ./run-tests.sh experience
#   ./run-tests.sh enrolment
#
# Tests run against PostgreSQL 16 (same as production) instead of SQLite,
# catching driver-specific bugs like case-sensitive LIKE and date handling.

set -euo pipefail

COMPOSE_FILE="docker-compose.test.yml"
SERVICES=("dashboard" "experience" "enrolment")

if [ $# -ge 1 ]; then
    SERVICES=("$1")
fi

# Map service names to APP_KEY values
declare -A APP_KEYS=(
    [dashboard]="base64:dGVzdGtleWZvcmRhc2hib2FyZHNlcnZpY2Ux"
    [experience]="base64:dGVzdGtleWZvcmV4cGVyaWVuY2VzZXJ2aWNl"
    [enrolment]="base64:dGVzdGtleWZvcmVucm9sbWVudHNlcnZpY2Ux"
)

FAILED=0

for service in "${SERVICES[@]}"; do
    echo ""
    echo "=== Running ${service}-service tests against PostgreSQL ==="
    # Use pwd -W on MSYS/Git Bash (Windows), fall back to pwd on Linux/Mac
    BASE_DIR="$(pwd -W 2>/dev/null || pwd)"
    if MSYS_NO_PATHCONV=1 docker compose -f "$COMPOSE_FILE" run --rm \
        -v "${BASE_DIR}/${service}-service:/app" \
        -e "APP_KEY=${APP_KEYS[$service]}" \
        test-runner \
        php vendor/bin/phpunit --colors=always; then
        echo "  ${service}-service: PASSED"
    else
        echo "  ${service}-service: FAILED"
        FAILED=1
    fi
done

echo ""
echo "=== Cleaning up ==="
docker compose -f "$COMPOSE_FILE" down

if [ $FAILED -ne 0 ]; then
    echo "Some tests FAILED."
    exit 1
fi

echo "All tests PASSED against PostgreSQL."
