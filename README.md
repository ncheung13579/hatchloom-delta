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
```

Services start sequentially via healthchecks: PostgreSQL → Enrolment Service → Experience Service → Dashboard Service. Each service automatically runs migrations and seeds test data. First startup takes approximately 30-45 seconds while images build and migrations run.

Once all containers are running, verify with:

```bash
curl http://localhost:8001/api/school/dashboard/health
curl http://localhost:8002/api/school/experiences/health
curl http://localhost:8003/api/school/enrolments/health
```

Each health endpoint returns `{ "status": "ok", "service": "<name>", "timestamp": "..." }`.

### Rebuilding After Code Changes

Code is baked into Docker images at build time (no volume mounts). After editing source files, rebuild before restarting:

```bash
docker compose build
docker compose up -d
```

To reset the database and start fresh:

```bash
docker compose down -v
docker compose up --build -d
```

## Local Development (Without Docker)

Each service is an independent Laravel application. To run one locally:

```bash
cd experience-service
composer install
cp .env.example .env
```

Edit `.env` to set `DB_HOST=localhost` (the `.env.example` defaults to `postgres`, the Docker hostname) and generate an app key:

```bash
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8002
```

Repeat for `enrolment-service` (port 8003) and `dashboard-service` (port 8001).

For cross-service HTTP calls to work locally, set these environment variables in each service's `.env`:

```
# Dashboard Service .env
EXPERIENCE_SERVICE_URL=http://localhost:8002
ENROLMENT_SERVICE_URL=http://localhost:8003

# Experience Service .env
ENROLMENT_SERVICE_URL=http://localhost:8003
```

## Running Tests

Tests require dev dependencies (phpunit), which are not included in the production Docker images. To run tests via Docker, install dev dependencies first:

```bash
docker compose exec experience-service composer install --dev
docker compose exec experience-service php artisan test

docker compose exec enrolment-service composer install --dev
docker compose exec enrolment-service php artisan test

docker compose exec dashboard-service composer install --dev
docker compose exec dashboard-service php artisan test
```

To run tests locally (requires PHP 8.2 and Composer installed on your machine):

```bash
cd experience-service
composer install
cp .env.testing .env
php artisan test
```

Repeat for `enrolment-service` and `dashboard-service`. The `.env.testing` files are pre-configured with test database credentials (`hatchloom_test`). You must have a local PostgreSQL instance with a `hatchloom_test` database available.

Tests are also run automatically via GitHub Actions on push to `main` and on pull requests.

## API Contract

**Integrating with Delta?** See [`API-CONTRACT.docx`](API-CONTRACT.docx) for full endpoint documentation, request/response shapes, authentication tokens, data ownership, and integration contracts for each team.

### Quick Reference (Mock Auth Tokens)

| Token | User | Role |
|-------|------|------|
| `test-admin-token` | Admin User (id=1) | school_admin |
| `test-teacher-token` | Ms. Smith (id=2) | school_teacher |
| `test-student-token` | Student 1 (id=4) | student |
| `test-parent-token` | Parent of Student 1 (id=14) | parent |
| `test-hatchloom-teacher-token` | Hatchloom Course Builder (id=15) | hatchloom_teacher |
| `test-hatchloom-admin-token` | Hatchloom Platform Admin (id=16) | hatchloom_admin |

## Environment Variables

| Variable | Default | Used By | Description |
|----------|---------|---------|-------------|
| `APP_NAME` | Laravel | All | Service name |
| `APP_KEY` | (generated) | All | Laravel encryption key |
| `APP_ENV` | local | All | Environment (local, testing, production) |
| `APP_DEBUG` | true | All | Enable debug mode |
| `APP_URL` | http://localhost:{port} | All | Base URL for the service |
| `DB_CONNECTION` | pgsql | All | Database driver |
| `DB_HOST` | postgres | All | Database host (Docker service name or IP) |
| `DB_PORT` | 5432 | All | Database port |
| `DB_DATABASE` | hatchloom | All | Database name |
| `DB_USERNAME` | hatchloom | All | Database user |
| `DB_PASSWORD` | secret | All | Database password |
| `EXPERIENCE_SERVICE_URL` | http://experience-service:8002 | Dashboard | URL for Experience Service |
| `ENROLMENT_SERVICE_URL` | http://enrolment-service:8003 | Dashboard, Experience | URL for Enrolment Service |
| `CACHE_STORE` | array | All | Cache driver |
| `SESSION_DRIVER` | array | All | Session driver |
| `QUEUE_CONNECTION` | sync | All | Queue driver (synchronous for D1) |

## CI/CD

GitHub Actions runs on push to `main` and on pull requests. The pipeline:

1. Runs PHPUnit tests for each service (with a PostgreSQL service container)
2. Builds all Docker images via `docker compose build`

See `.github/workflows/ci.yml` for details.

## Known Limitations

- **Authentication is mocked** -- hardcoded bearer token-to-user mapping via `MockAuthMiddleware`. Production auth will use session tokens validated by the API Gateway (see API contract for details).
- **Course data is mocked** -- the course catalogue from Team Papa is provided by a `MockCourseDataProvider` class rather than real HTTP calls. Only course IDs 1-5 exist.
- **Credential data is mocked** -- `MockCredentialDataProvider` returns sample data for all students. Real credential data will come from Karl's credential engine.
- **Progress data is mocked** -- `MockStudentProgressProvider` returns placeholder values. Real progress data will come from Team Papa's Course Service.
- **No real inter-team integration** -- all cross-team data (courses, credentials, progress) is provided by mock providers implementing strategy-pattern interfaces. See the API contract "Data Ownership" section for swap instructions.
- **School scoping uses mock data** -- only one school (Ridgewood Academy) is seeded. Multi-tenant isolation is implemented but not tested across multiple schools.
- **No API gateway** -- services call each other directly over the Docker network.

## Team Members

**CSSD 2203 (Software Design):** Bhagya, Nathan, Neharika, Shlok

**CSSD 2211 (Cloud Computing):** Bhagya, Miguel, Neharika
