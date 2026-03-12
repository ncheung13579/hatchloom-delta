# Hatchloom Team Delta -- School Administration Microservices

Team Delta's School Administration backend for the Hatchloom digital learning platform. Hatchloom is a learning and community platform for teens aged 12-17, built across four York University student teams. Team Delta owns the School Administration module (Screens 300-303), which school administrators use to manage experiences, cohorts, enrolments, and view dashboards.

This project is built as three independent Laravel microservices for the CSSD 2211 (Cloud Computing) deliverable, structured so they can be merged into a single monolith later.

## Architecture

```
                         ┌─────────────────────────┐
                         │    Dashboard Service     │
                         │       (port 8001)        │
                         │    Aggregation layer     │
                         │       Screen 300         │
                         └─────┬───────────┬────────┘
                               │           │
                  HTTP GET/    │           │    HTTP GET/
                  Forward Auth │           │    Forward Auth
                               │           │
              ┌────────────────▼┐         ┌▼─────────────────┐
              │   Experience    │         │    Enrolment      │
              │    Service      │────────>│     Service       │
              │  (port 8002)    │ HTTP    │   (port 8003)     │
              │ Screens 301-302 │ GET    │    Screen 303      │
              └────────┬────────┘         └────────┬──────────┘
                       │                           │
                       └───────────┬───────────────┘
                                   │
                          ┌────────▼─────────┐
                          │  PostgreSQL 16   │
                          │   (port 5432)    │
                          │ Shared database  │
                          └──────────────────┘
```

| Service | Port | Owns Tables | Description |
|---------|------|-------------|-------------|
| Dashboard Service | 8001 | None (aggregation only) | School Admin Dashboard (Screen 300) |
| Experience Service | 8002 | `experiences`, `experience_courses` | Experience management (Screens 301, 302) |
| Enrolment Service | 8003 | `cohorts`, `cohort_enrolments` | Cohort and enrolment management (Screen 303) |

All three services connect to a single shared PostgreSQL database. Each service runs its own migrations for its owned tables. The `schools` and `users` tables are seeded as mock reference data.

## Prerequisites

- **Docker** and **Docker Compose** (required for containerized deployment)
- **PHP 8.2** and **Composer** (for local development without Docker)
- **PostgreSQL 16** (for local development without Docker)

## Quick Start (Docker)

```bash
# Clone the repository
git clone <repo-url> hatchloom-delta
cd hatchloom-delta

# Build and start all services
docker compose up --build -d

# Run migrations and seed data for each service
docker compose exec experience-service php artisan migrate --seed
docker compose exec enrolment-service php artisan migrate --seed
docker compose exec dashboard-service php artisan migrate --seed

# Verify all services are running
curl http://localhost:8001/api/school/dashboard/health
curl http://localhost:8002/api/school/experiences/health
curl http://localhost:8003/api/school/enrolments/health
```

Each health endpoint returns `{ "status": "ok", "service": "<name>", "timestamp": "..." }`.

## Local Development (Without Docker)

Each service is an independent Laravel application. To run one locally:

```bash
cd experience-service
composer install
cp .env.example .env   # or create .env with DB credentials
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8002
```

Repeat for `enrolment-service` (port 8003) and `dashboard-service` (port 8001).

For the Dashboard Service to call the other services, set these environment variables:

```
EXPERIENCE_SERVICE_URL=http://localhost:8002
ENROLMENT_SERVICE_URL=http://localhost:8003
```

## Running Tests

```bash
# Via Docker
docker compose exec experience-service php artisan test
docker compose exec enrolment-service php artisan test
docker compose exec dashboard-service php artisan test

# Locally (from each service directory)
cd experience-service && php artisan test
cd enrolment-service && php artisan test
cd dashboard-service && php artisan test
```

## API Endpoint Summary

All authenticated endpoints require `Authorization: Bearer {token}` header.

### Dashboard Service (port 8001)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/dashboard` | Aggregated school admin dashboard |
| GET | `/api/school/dashboard/students/{id}` | Student drill-down with enrolment details |
| GET | `/api/school/dashboard/reporting/pos-coverage` | Alberta PoS curriculum coverage report |
| GET | `/api/school/dashboard/reporting/engagement` | Student engagement metrics |
| GET | `/api/school/dashboard/widgets` | All dashboard widgets |
| GET | `/api/school/dashboard/widgets/{type}` | Specific dashboard widget by type |
| GET | `/api/school/dashboard/health` | Health check (no auth required) |

### Experience Service (port 8002)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/experiences` | List experiences (`?search=`, `?page=`, `?per_page=`) |
| POST | `/api/school/experiences` | Create a new experience |
| GET | `/api/school/experiences/{id}` | Get experience detail |
| PUT | `/api/school/experiences/{id}` | Update an experience |
| DELETE | `/api/school/experiences/{id}` | Archive (soft-delete) an experience |
| GET | `/api/school/experiences/{id}/students` | Enrolled students across all cohorts |
| GET | `/api/school/experiences/{id}/students/{studentId}` | Student drill-down within experience |
| GET | `/api/school/experiences/{id}/students/export` | Export experience students as CSV |
| GET | `/api/school/experiences/{id}/contents` | Course contents and delivery schedule |
| GET | `/api/school/experiences/{id}/statistics` | Per-experience statistics |
| GET | `/api/school/experiences/health` | Health check (no auth required) |

### Enrolment Service (port 8003)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/cohorts` | List cohorts (`?experience_id=`, `?status=`) |
| POST | `/api/school/cohorts` | Create a new cohort |
| GET | `/api/school/cohorts/{id}` | Get cohort detail |
| PUT | `/api/school/cohorts/{id}` | Update a cohort |
| PATCH | `/api/school/cohorts/{id}/activate` | Transition cohort to active |
| PATCH | `/api/school/cohorts/{id}/complete` | Transition cohort to completed |
| POST | `/api/school/cohorts/{id}/enrolments` | Enrol a student in a cohort |
| DELETE | `/api/school/cohorts/{id}/enrolments/{studentId}` | Remove a student (soft delete) |
| GET | `/api/school/enrolments` | School-wide enrolment overview (`?search=`, `?experience_id=`, `?cohort_id=`, `?grade=`) |
| GET | `/api/school/enrolments/students/{studentId}` | Student drill-down from enrolment context |
| GET | `/api/school/enrolments/statistics` | Enrolment statistics with warnings |
| GET | `/api/school/enrolments/export` | Export enrolments as CSV |
| GET | `/api/school/enrolments/health` | Health check (no auth required) |

## Authentication (Mock -- D1)

All authenticated endpoints require an `Authorization: Bearer {token}` header. For D1, authentication is mocked with hardcoded token-to-user mappings:

| Token | User | Role | School |
|-------|------|------|--------|
| `test-admin-token` | Admin User (id=1) | school_admin | Ridgewood Academy (id=1) |
| `test-teacher-token` | Ms. Smith (id=2) | school_teacher | Ridgewood Academy (id=1) |

Example request:
```bash
curl -H "Authorization: Bearer test-admin-token" http://localhost:8002/api/school/experiences
```

## Environment Variables

| Variable | Default | Used By | Description |
|----------|---------|---------|-------------|
| `APP_NAME` | Laravel | All | Service name |
| `APP_KEY` | (generated) | All | Laravel encryption key |
| `APP_ENV` | local | All | Environment (local, testing, production) |
| `APP_DEBUG` | true | All | Enable debug mode |
| `DB_CONNECTION` | pgsql | All | Database driver |
| `DB_HOST` | postgres | All | Database host (Docker service name or IP) |
| `DB_PORT` | 5432 | All | Database port |
| `DB_DATABASE` | hatchloom | All | Database name |
| `DB_USERNAME` | hatchloom | All | Database user |
| `DB_PASSWORD` | secret | All | Database password |
| `EXPERIENCE_SERVICE_URL` | http://experience-service:8002 | Dashboard | URL for Experience Service |
| `ENROLMENT_SERVICE_URL` | http://enrolment-service:8003 | Dashboard | URL for Enrolment Service |
| `CACHE_STORE` | array | All | Cache driver |
| `SESSION_DRIVER` | array | All | Session driver |

## CI/CD

GitHub Actions runs on push to `main` and on pull requests. The pipeline:

1. Runs PHPUnit tests for each service (with a PostgreSQL service container)
2. Builds all Docker images via `docker compose build`

See `.github/workflows/ci.yml` for details.

## Known Limitations (D1)

- **Authentication is mocked** -- hardcoded bearer token-to-user mapping; no real OAuth/JWT auth system.
- **Course data is mocked** -- the course catalogue from Team Papa is provided by a `MockCourseDataProvider` class rather than real HTTP calls.
- **No real inter-team integration** -- all cross-team data (courses, credentials, LaunchPad) is seeded or hardcoded.
- **Credential data returns empty arrays** -- Karl's credential engine is not yet integrated.
- **School scoping uses mock data** -- only one school (Ridgewood Academy) is seeded; multi-tenant isolation is implemented but not tested across multiple schools.
- **No API gateway** -- services call each other directly over the Docker network.

## Team Members

**CSSD 2203 (Software Design):** Bhagya, Nathan, Neharika, Shlok

**CSSD 2211 (Cloud Computing):** Bhagya, Miguel, Neharika
