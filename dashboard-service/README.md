# Dashboard Service

**Port:** 8001 | **Tables owned:** None (aggregation layer only)

## Purpose

The Dashboard Service is the aggregation layer for the School Admin Dashboard (Screen 300). It does not own any database tables. Instead, it calls the Experience Service and Enrolment Service over HTTP to collect data, then assembles the aggregated dashboard response.

Key responsibilities:
- Aggregated school overview with cohort counts, student counts, and statistics
- Student drill-down showing enrolment history and progress
- Reporting endpoints for PoS curriculum coverage and engagement metrics
- Dashboard widgets via Factory Method pattern

## Endpoints

All authenticated endpoints require `Authorization: Bearer {token}`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/dashboard` | Aggregated dashboard with school info, cohort counts, student statistics, and warnings |
| GET | `/api/school/dashboard/students/{id}` | Student drill-down with enrolment details, progress, and credentials |
| GET | `/api/school/dashboard/reporting/pos-coverage` | Per-student Alberta PoS curriculum coverage report |
| GET | `/api/school/dashboard/reporting/engagement` | Student engagement rates and activity metrics |
| GET | `/api/school/dashboard/widgets` | All dashboard widgets |
| GET | `/api/school/dashboard/widgets/{type}` | Specific dashboard widget by type |
| GET | `/api/school/dashboard/health` | Health check (no auth required) |

## Dependencies

This service depends on the other two services being available over HTTP:

| Dependency | URL (Docker) | Purpose |
|------------|--------------|---------|
| Experience Service | `http://experience-service:8002` | Experience listing, course data, statistics |
| Enrolment Service | `http://enrolment-service:8003` | Enrolment statistics, student data, warnings |

If a downstream service is unavailable, the Dashboard Service returns a degraded response with the data it can access and includes a warning.

## Environment Variables

In addition to the standard Laravel and database variables (see root README):

| Variable | Default | Description |
|----------|---------|-------------|
| `EXPERIENCE_SERVICE_URL` | `http://experience-service:8002` | Experience Service base URL |
| `ENROLMENT_SERVICE_URL` | `http://enrolment-service:8003` | Enrolment Service base URL |

## Migrations and Seeding

```bash
php artisan migrate --seed
```

Seeds shared reference tables (`schools`, `users`) only. This service owns no domain tables.

## Running Tests

```bash
# Locally
cd dashboard-service
composer install
php artisan test

# Via Docker
docker compose exec dashboard-service php artisan test
```

Tests use `Http::fake()` to mock downstream service calls, so no running PostgreSQL or sibling services are needed for testing.
