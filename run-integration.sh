#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────
# Boot all 3 services + PostgreSQL and run integration tests.
#
# Usage:
#   ./run-integration.sh
#
# This builds all service images, starts them with a shared
# PostgreSQL database, runs migrations + seeds, then executes
# the integration test suite via the integration-runner container.
# ─────────────────────────────────────────────────────────────────

set -euo pipefail

COMPOSE_FILE="docker-compose.integration.yml"

echo ""
echo "=== Building and starting services ==="
echo "  (PostgreSQL → Enrolment → Experience → Dashboard)"
echo ""

# Build all images first
docker compose -f "$COMPOSE_FILE" build

# Run integration-runner (it depends on all services being healthy,
# so Docker Compose will start everything in the right order)
EXIT_CODE=0
docker compose -f "$COMPOSE_FILE" run --rm integration-runner || EXIT_CODE=$?

echo ""
echo "=== Cleaning up ==="
docker compose -f "$COMPOSE_FILE" down

if [ $EXIT_CODE -ne 0 ]; then
    echo "Integration tests FAILED."
    exit 1
fi

echo "Integration tests PASSED."
