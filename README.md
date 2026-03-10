# Hatchloom Team Delta — School Administration Microservices

Team Delta's School Administration backend for the Hatchloom digital learning platform.
Built as 3 independent Laravel microservices for the CSSD 2211 (Cloud Computing) deliverable.

## Architecture Overview

```
                    ┌──────────────────────┐
                    │   Dashboard Service  │
                    │     (port 8001)      │
                    │   Aggregation layer  │
                    └──────┬──────┬────────┘
                           │      │
              HTTP GET     │      │     HTTP GET
                           │      │
            ┌──────────────▼┐    ┌▼───────────────┐
            │  Experience   │    │   Enrolment     │
            │   Service     │    │    Service      │
            │  (port 8002)  │    │   (port 8003)   │
            └──────┬────────┘    └──────┬──────────┘
                   │                    │
                   └────────┬───────────┘
                            │
                   ┌────────▼─────────┐
                   │   PostgreSQL 16  │
                   │   (port 5432)    │
                   │  Shared database │
                   └──────────────────┘
```

| Service | Port | Owns Tables | Description |
|---------|------|-------------|-------------|
| Dashboard Service | 8001 | None (aggregation only) | School Admin Dashboard (Screen 300) |
| Experience Service | 8002 | `experiences`, `experience_courses` | Experience management (Screens 301, 302) |
| Enrolment Service | 8003 | `cohorts`, `cohort_enrolments` | Cohort & enrolment management (Screen 303) |

## Prerequisites

- **Docker** and **Docker Compose** (required)
- **PHP 8.2** and **Composer** (for local development without Docker)

## Quick Start (Docker)

```bash
# Build and start all services
docker compose up --build

# Run migrations (in another terminal)
docker compose exec experience-service php artisan migrate --seed
docker compose exec enrolment-service php artisan migrate --seed
docker compose exec dashboard-service php artisan migrate --seed

# Verify services are running
curl http://localhost:8001/api/school/dashboard/health
curl http://localhost:8002/api/school/experiences/health
curl http://localhost:8003/api/school/enrolments/health
```

## Running Locally Without Docker

Each service is an independent Laravel application. To run one locally:

```bash
cd experience-service
cp .env.example .env
# Edit .env: set DB_HOST=127.0.0.1, point to a running PostgreSQL instance
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8002
```

Repeat for `enrolment-service` (port 8003) and `dashboard-service` (port 8001).

## Running Tests

Each service has its own PHPUnit test suite using SQLite in-memory:

```bash
# Experience Service
cd experience-service && php artisan test

# Enrolment Service
cd enrolment-service && php artisan test

# Dashboard Service
cd dashboard-service && php artisan test
```

Or run tests in Docker:

```bash
docker compose exec experience-service php artisan test
docker compose exec enrolment-service php artisan test
docker compose exec dashboard-service php artisan test
```

## API Endpoint Summary

### Dashboard Service (port 8001)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/dashboard` | Aggregated school admin dashboard |
| GET | `/api/school/dashboard/students/{id}` | Student drill-down detail |
| GET | `/api/school/dashboard/health` | Health check |

### Experience Service (port 8002)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/experiences` | List experiences (supports `?search=`, `?page=`, `?per_page=`) |
| POST | `/api/school/experiences` | Create new experience |
| GET | `/api/school/experiences/{id}` | Get experience detail |
| PUT | `/api/school/experiences/{id}` | Update experience |
| DELETE | `/api/school/experiences/{id}` | Archive (soft-delete) experience |
| GET | `/api/school/experiences/{id}/students` | Enrolled students for experience |
| GET | `/api/school/experiences/{id}/contents` | Course contents and delivery |
| GET | `/api/school/experiences/{id}/statistics` | Experience statistics |
| GET | `/api/school/experiences/health` | Health check |

### Enrolment Service (port 8003)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/cohorts` | List cohorts (supports `?experience_id=`, `?status=`) |
| POST | `/api/school/cohorts` | Create new cohort |
| GET | `/api/school/cohorts/{id}` | Get cohort detail |
| PUT | `/api/school/cohorts/{id}` | Update cohort |
| PATCH | `/api/school/cohorts/{id}/activate` | Transition cohort to active |
| PATCH | `/api/school/cohorts/{id}/complete` | Transition cohort to completed |
| POST | `/api/school/cohorts/{id}/enrolments` | Enrol a student |
| DELETE | `/api/school/cohorts/{id}/enrolments/{studentId}` | Remove a student (soft delete) |
| GET | `/api/school/enrolments` | School-wide enrolment overview |
| GET | `/api/school/enrolments/statistics` | Enrolment statistics with warnings |
| GET | `/api/school/enrolments/export` | Export enrolments as CSV |
| GET | `/api/school/enrolments/health` | Health check |

## Authentication (Mock for D1)

All endpoints require `Authorization: Bearer {token}` header.

| Token | User | Role |
|-------|------|------|
| `test-admin-token` | Admin User (id=1) | school_admin |
| `test-teacher-token` | Ms. Smith (id=2) | school_teacher |

Example:
```bash
curl -H "Authorization: Bearer test-admin-token" http://localhost:8002/api/school/experiences
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | Laravel | Service name |
| `APP_KEY` | (generated) | Laravel encryption key |
| `DB_CONNECTION` | pgsql | Database driver |
| `DB_HOST` | postgres | Database host (Docker network name) |
| `DB_PORT` | 5432 | Database port |
| `DB_DATABASE` | hatchloom | Database name |
| `DB_USERNAME` | hatchloom | Database user |
| `DB_PASSWORD` | secret | Database password |
| `EXPERIENCE_SERVICE_URL` | http://experience-service:8002 | Dashboard → Experience Service URL |
| `ENROLMENT_SERVICE_URL` | http://enrolment-service:8003 | Dashboard → Enrolment Service URL |

## Known Limitations (D1)

- **Authentication is mocked** — hardcoded token-to-user mapping, no real auth system
- **Upstream data is mocked** — course catalogue from Team Papa is provided by `MockCourseDataProvider`
- **No real inter-team integration** — all cross-team data is seeded or hardcoded
- **Credential data** — empty arrays returned (Karl's credential engine not integrated)
- **Dashboard cohort counts** — placeholder values pending full enrolment service integration
- **Experience statistics** — returns mock values until full cohort/enrolment data flows

## Team

- **CSSD 2203 (Software Design):** Bhagya, Nathan, Neharika, Shlok
- **CSSD 2211 (Cloud Computing):** Bhagya, Miguel, Neharika
