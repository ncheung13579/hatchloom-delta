# Dashboard Service

**Port:** 8001
**Tables owned:** None (aggregation layer only)

## Overview

The Dashboard Service aggregates data from the Experience Service and Enrolment Service to provide the School Admin Dashboard (Screen 300). It makes HTTP calls to downstream services and combines the responses.

## Migrations & Seeding

```bash
php artisan migrate --seed
```

Seeds shared reference tables (`schools`, `users`) only.

## Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/dashboard` | Aggregated dashboard overview with summary block |
| GET | `/api/school/dashboard/students/{id}` | Student drill-down with credentials and curriculum mapping |
| GET | `/api/school/dashboard/reporting/pos-coverage` | Per-student Alberta PoS curriculum coverage |
| GET | `/api/school/dashboard/reporting/engagement` | Student engagement rates and activity metrics |
| GET | `/api/school/dashboard/health` | Health check |

## Running Tests

```bash
php artisan test
```

Tests use `Http::fake()` to mock downstream service calls.
