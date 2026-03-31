# Hatchloom Team Delta -- School Administration Microservices

Team Delta's School Administration backend for the Hatchloom digital learning platform. Hatchloom is a learning and community platform for teens aged 12-17, built across four York University student teams. Team Delta owns the School Administration module (Screens 300-303), which school administrators use to manage experiences, cohorts, enrolments, and view dashboards.

This project is built as three independent Laravel microservices for the CSSD 2211 (Cloud Computing) and CSSD 2203 (Software Design) deliverables. The frontend is built separately by Team Romeo.

## What We Built

- **Screen 300 -- School Admin Dashboard**: Aggregated KPIs (Problems Tackled, Active Ventures, Students, Experiences, Credit Progress, Timely Completion), warning banners for unassigned students, tabbed Students/Cohorts tables, engagement metrics, and student drill-down with progress, credentials, and curriculum mapping
- **Screen 301 -- Experiences Dashboard**: Searchable table of learning experiences with status, course contents, and cohort links. Create, edit, and archive experiences. Assign courses from Team Papa's catalogue.
- **Screen 302 -- Experience Detail**: Breadcrumb navigation, metric cards, Content & Delivery section with courses and block counts, cohort management (create, activate, complete), paginated student table with CSV export, and individual student drill-down
- **Screen 303 -- Enrolment**: School-wide enrolment overview with grade/experience/cohort filters, metric cards, attention banners for unassigned students, enrol/remove students, CSV export, and student detail view with credentials

Read-only API views are provided for **students** (personal enrolments and progress) and **parents** (linked children's data only, enforced by backend).

---

## Architecture

```
              +-----------+   +-----------+   +-------------+
              | Dashboard |   | Experience|   |  Enrolment  |
              |  Service  |   |  Service  |   |   Service   |
              |(port 8001)|   |(port 8002)|   | (port 8003) |
              | Screen 300|   |Screens    |   |  Screen 303 |
              |Aggregation|   |301-302    |   |             |
              +-----+-----+  +-----+-----+   +------+------+
                    |               |                |
                    +---------------+----------------+
                                    |
                           +--------v---------+
                           |  PostgreSQL 16   |
                           |   (port 5432)    |
                           | Shared database  |
                           +------------------+
```

| Service | Port | Owns Tables | Description |
|---------|------|-------------|-------------|
| Dashboard Service | 8001 | None (aggregation only) | School Admin Dashboard (Screen 300). Calls Experience and Enrolment services over HTTP. |
| Experience Service | 8002 | `experiences`, `experience_courses` | Experience management (Screens 301, 302) |
| Enrolment Service | 8003 | `cohorts`, `cohort_enrolments` | Cohort and enrolment management (Screen 303) |

All three services connect to a single shared PostgreSQL database. Each service runs its own migrations for its owned tables. The `schools` and `users` tables are seeded as reference data.

**Key architectural decisions:**

- The **Dashboard Service owns no database tables**. It calls the other two services over HTTP and merges the results. If one downstream service is down, it still returns a partial response with a `service_degraded` warning (graceful degradation).
- The **Experience Service** owns experiences and their course assignments.
- The **Enrolment Service** owns cohorts and student enrolments, including the cohort state machine.
- External team data (courses from Papa, auth from Quebec, credentials from Karl) is provided by **strategy-pattern providers** with both mock and HTTP implementations.

---

## Prerequisites

- **Docker** and **Docker Compose** (required for containerized deployment)
- **PHP 8.2** and **Composer** (for local development without Docker)
- **PostgreSQL 16** (for local development without Docker)

---

## Quick Start (Docker)

```bash
# Clone the repository
git clone <repo-url> hatchloom-delta
cd hatchloom-delta

# Build and start all services
docker compose up --build -d
```

Services start sequentially via healthchecks: PostgreSQL -> Enrolment Service -> Experience Service -> Dashboard Service. Each service automatically runs migrations and seeds test data. First startup takes approximately 30-45 seconds while images build.

Check that all containers are running:

```bash
docker compose ps
```

Verify backend health:

```bash
curl -4 http://localhost:8001/api/school/dashboard/health
curl -4 http://localhost:8002/api/school/experiences/health
curl -4 http://localhost:8003/api/school/enrolments/health
```

Each health endpoint returns `{ "status": "ok", "service": "<name>", "timestamp": "..." }`.

**Windows users:** In PowerShell, `curl` is an alias for `Invoke-WebRequest` and will not work. Use `curl.exe` instead:

```powershell
curl.exe -4 http://localhost:8001/api/school/dashboard/health
curl.exe -4 http://localhost:8002/api/school/experiences/health
curl.exe -4 http://localhost:8003/api/school/enrolments/health
```

The `-4` flag forces IPv4. Docker containers bind to `0.0.0.0` (IPv4 only), and Windows often defaults to IPv6 (`::1`), which will cause "connection refused" errors without this flag.

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

---

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

---

## Authentication and Roles

The system supports two authentication modes, toggled by the `AUTH_MODE` environment variable in `docker-compose.yml`:

- **`AUTH_MODE=http`** (default): Real authentication via Team Quebec's User Service. JWT bearer tokens are validated against Quebec's `/auth/validate` endpoint. This is the production mode.
- **`AUTH_MODE=mock`**: Static token-to-user mapping via `MockAuthMiddleware`. No external service required. Used for standalone development and testing.

The toggle is configured in each service's `AppServiceProvider` and `bootstrap/app.php`. Changing `AUTH_MODE` also switches all other strategy-pattern providers (course data, credentials, etc.) between mock and HTTP implementations.

### Mock Auth Tokens (for API testing)

When `AUTH_MODE=mock`, the following bearer tokens are accepted:

| Token | User | Role |
|-------|------|------|
| `test-admin-token` | Admin User (id=1) | school_admin |
| `test-teacher-token` | Ms. Smith (id=2) | school_teacher |
| `test-student-token` | Student 1 (id=4) | student |
| `test-parent-token` | Parent of Student 1 (id=14) | parent |
| `test-hatchloom-teacher-token` | Hatchloom Course Builder (id=15) | hatchloom_teacher |
| `test-hatchloom-admin-token` | Hatchloom Platform Admin (id=16) | hatchloom_admin |

Example API call:

```bash
curl -4 -H "Authorization: Bearer test-admin-token" http://localhost:8001/api/school/dashboard
```

### Roles

| Role | User | Permissions |
|------|------|-------------|
| `school_admin` | Admin User (id=1) | Read all screens. Enrol/remove students. **Cannot** create/edit experiences or cohorts. |
| `school_teacher` | Ms. Smith (id=2) | Full write access: create/edit experiences, create/edit/activate/complete cohorts, enrol/remove students. |
| `student` | Student 1 (id=4) | Read own enrolments, progress, and credentials only. |
| `parent` | Parent User (id=14) | Read linked children's data only (children: student ids 4 and 5). Cannot see other students. |

Teacher-only actions return 403 for admins, students, and parents. Parent-child links use a `parent_student_links` join table; the backend verifies the parent owns the child before returning data.

---

## API Endpoint Reference

### Dashboard Service (Port 8001)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/school/dashboard` | Admin/Teacher | Full dashboard overview with KPIs |
| GET | `/api/school/dashboard/students/{id}` | All roles (scoped) | Student drill-down with progress, credentials, curriculum mapping |
| GET | `/api/school/dashboard/widgets` | Admin/Teacher | All dashboard widgets |
| GET | `/api/school/dashboard/widgets/{type}` | Admin/Teacher | Single widget (`cohort_summary`, `student_table`, `engagement_chart`) |
| GET | `/api/school/dashboard/reporting/pos-coverage` | Admin/Teacher | Alberta PoS curriculum coverage |
| GET | `/api/school/dashboard/reporting/engagement` | Admin/Teacher | Engagement rates |
| GET | `/api/school/dashboard/health` | Public | Health check |

### Experience Service (Port 8002)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/school/experiences` | All roles | List experiences (Screen 301) |
| POST | `/api/school/experiences` | **Teacher only** | Create experience |
| GET | `/api/school/experiences/{id}` | All roles | Experience detail |
| PUT | `/api/school/experiences/{id}` | **Teacher only** | Update experience |
| DELETE | `/api/school/experiences/{id}` | **Teacher only** | Archive experience |
| GET | `/api/school/experiences/{id}/students` | Admin/Teacher | Student list for experience (paginated) |
| GET | `/api/school/experiences/{id}/students/{studentId}` | Admin/Teacher | Student detail within experience |
| GET | `/api/school/experiences/{id}/students/export` | Admin/Teacher | CSV export of students |
| GET | `/api/school/experiences/{id}/contents` | All roles | Course blocks and contents |
| GET | `/api/school/experiences/{id}/statistics` | Admin/Teacher | Enrolment/completion stats |
| GET | `/api/school/courses` | All roles | Course catalogue (from provider) |
| GET | `/api/school/experiences/health` | Public | Health check |

### Enrolment Service (Port 8003)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/school/cohorts` | All roles | List cohorts (filterable by experience_id, status) |
| POST | `/api/school/cohorts` | **Teacher only** | Create cohort |
| GET | `/api/school/cohorts/{id}` | All roles | Cohort detail |
| PUT | `/api/school/cohorts/{id}` | **Teacher only** | Update cohort |
| PATCH | `/api/school/cohorts/{id}/activate` | **Teacher only** | Activate cohort (State pattern) |
| PATCH | `/api/school/cohorts/{id}/complete` | **Teacher only** | Complete cohort (State pattern) |
| POST | `/api/school/cohorts/{id}/enrolments` | Admin/Teacher | Enrol student in cohort |
| DELETE | `/api/school/cohorts/{id}/enrolments/{studentId}` | Admin/Teacher | Remove student (soft-delete) |
| GET | `/api/school/enrolments` | All roles | Paginated student overview |
| GET | `/api/school/enrolments/statistics` | Admin/Teacher | Aggregate stats + warnings |
| GET | `/api/school/enrolments/export` | Admin/Teacher | CSV export |
| GET | `/api/school/enrolments/students/{id}` | All roles (scoped) | Student enrolment detail with credentials |
| GET | `/api/school/enrolments/health` | Public | Health check |

### Quick API Walkthrough

With services running and `AUTH_MODE=mock`, exercise the main flows via curl:

```bash
# Dashboard overview (admin)
curl -4 -H "Authorization: Bearer test-admin-token" http://localhost:8001/api/school/dashboard

# Student drill-down
curl -4 -H "Authorization: Bearer test-admin-token" http://localhost:8001/api/school/dashboard/students/4

# List experiences
curl -4 -H "Authorization: Bearer test-teacher-token" http://localhost:8002/api/school/experiences

# Create an experience (teacher only)
curl -4 -X POST -H "Authorization: Bearer test-teacher-token" -H "Content-Type: application/json" \
  -d '{"name":"Financial Literacy 101","description":"Intro to personal finance","course_ids":[1,2]}' \
  http://localhost:8002/api/school/experiences

# List cohorts
curl -4 -H "Authorization: Bearer test-teacher-token" http://localhost:8003/api/school/cohorts

# Activate a cohort (State pattern: not_started -> active)
curl -4 -X PATCH -H "Authorization: Bearer test-teacher-token" http://localhost:8003/api/school/cohorts/2/activate

# Enrol a student
curl -4 -X POST -H "Authorization: Bearer test-teacher-token" -H "Content-Type: application/json" \
  -d '{"student_id":12}' http://localhost:8003/api/school/cohorts/2/enrolments

# Enrolment overview with filters
curl -4 -H "Authorization: Bearer test-admin-token" "http://localhost:8003/api/school/enrolments?grade=10"

# CSV export
curl -4 -H "Authorization: Bearer test-admin-token" http://localhost:8003/api/school/enrolments/export

# Role-based access: student blocked from teacher-only action (returns 403)
curl -4 -X POST -H "Authorization: Bearer test-student-token" -H "Content-Type: application/json" \
  -d '{"name":"Test"}' http://localhost:8002/api/school/experiences

# No token (returns 401)
curl -4 http://localhost:8001/api/school/dashboard
```

---

## Design Patterns

Six design patterns are implemented:

### 1. Strategy Pattern

All three services use interfaces for external data sources. Mock and HTTP implementations are bound in `AppServiceProvider`, toggled by `AUTH_MODE`.

| Interface | Mock Implementation | HTTP Implementation | External Source |
|-----------|-------------------|---------------------|-----------------|
| Auth middleware | `MockAuthMiddleware` | `HttpAuthMiddleware` | Quebec User Service (JWT) |
| `CourseDataProviderInterface` | `MockCourseDataProvider` | `HttpCourseDataProvider` | Papa Course Service |
| `StudentProgressProviderInterface` | `MockStudentProgressProvider` | `HttpStudentProgressProvider` | Papa Course Service |
| `LaunchPadDataProviderInterface` | `MockLaunchPadDataProvider` | `HttpLaunchPadDataProvider` | Quebec User Service |
| `CredentialDataProviderInterface` | `MockCredentialDataProvider` | `HttpCredentialDataProvider` | Karl's Credential Engine |

Switching from mock to HTTP requires zero changes to controllers or services -- only the binding in `AppServiceProvider`.

### 2. Factory Method Pattern

`dashboard-service/app/Factories/DashboardWidgetFactory.php` -- maps widget type strings (`cohort_summary`, `student_table`, `engagement_chart`) to widget classes. Adding a new widget means adding one line to `WIDGET_MAP`.

### 3. State Pattern

`enrolment-service/app/States/` -- Cohort lifecycle: `not_started` -> `active` -> `completed`. Each state is a class implementing `CohortState`. Transitions are one-directional. The controller delegates to the state object rather than having if/else chains.

### 4. Observer Pattern

`enrolment-service/app/Events/` -- When a student is enrolled or removed, events are dispatched (`StudentEnrolled`, `StudentRemoved`). Independent listeners react: `UpdateDashboardCounts`, `NotifyTeacher`, `TriggerCredentialCheck`.

### 5. Repository Pattern

`DashboardService` abstracts away whether data comes from HTTP calls, database queries, or mock providers. Controllers never make HTTP calls directly.

### 6. Dependency Injection (Laravel Container)

All provider interfaces are bound in the service container. Services declare dependencies in constructor parameters. Laravel automatically injects the correct implementation based on `AUTH_MODE`.

---

## External Integrations (Strategy Pattern)

All external data dependencies use the Strategy pattern with mock and HTTP implementations, toggled by `AUTH_MODE`:

| Interface | Mock Provider | HTTP Provider | External Service |
|-----------|--------------|---------------|------------------|
| Auth middleware | `MockAuthMiddleware` | `HttpAuthMiddleware` | Quebec User Service (JWT validation) |
| `CourseDataProviderInterface` | `MockCourseDataProvider` | `HttpCourseDataProvider` | Papa Course Service (catalogue, block data) |
| `StudentProgressProviderInterface` | `MockStudentProgressProvider` | `HttpStudentProgressProvider` | Papa Course Service (completion, credits) |
| `LaunchPadDataProviderInterface` | `MockLaunchPadDataProvider` | `HttpLaunchPadDataProvider` | Quebec User Service (venture counts) |
| `CredentialDataProviderInterface` | `MockCredentialDataProvider` | `HttpCredentialDataProvider` | Karl's Credential Engine (badges, certificates, PoS mapping) |

When `AUTH_MODE=mock`, mock providers return realistic static data demonstrating the correct response structures. When `AUTH_MODE=http`, HTTP providers call the actual external services. Switching requires no changes to controllers or services.

**How mocks work:** Each mock provider returns hardcoded data that demonstrates the correct response structure. For example, `MockCredentialDataProvider` returns three Alberta PoS areas (Business Studies, CTF Design Studies, CALM) with specific requirement codes and coverage percentages.

**How to swap providers manually:** Change one line in `AppServiceProvider::register()`:

```php
// Mock (current when AUTH_MODE=mock)
$this->app->bind(CourseDataProviderInterface::class, MockCourseDataProvider::class);

// HTTP (current when AUTH_MODE=http)
$this->app->bind(CourseDataProviderInterface::class, HttpCourseDataProvider::class);
```

---

## Seed Data

Pre-seeded data for a realistic school scenario. Seeders run automatically during `docker compose up`.

| Entity | Count | Details |
|--------|-------|---------|
| School | 1 | Ridgewood Academy |
| Users | 16 | 1 admin, 2 teachers, 10 students (grades 8-12), 1 parent, 2 platform staff |
| Experiences | 3 | Business Foundations (active), Tech Explorers (active), Creative Problem Solving (draft) |
| Courses | 5 | Mock catalogue: Entrepreneurship, Financial Literacy, Marketing, Digital Skills, Coding |
| Cohorts | 5 | A (active, 6 students), B (not started, 0), C (active, 3), D (completed, 4), E (not started, 0) |
| Enrolments | 13 | Including multi-cohort students, removed students, and 2 unassigned students for warning banners |

Key student IDs for testing:

| Student | ID | Grade | Cohort Status |
|---------|----|-------|---------------|
| Students 1-6 | 4-9 | 8-12 | Enrolled in Cohort A (active) |
| Students 7-9 | 10-12 | 10-12 | Student 7-8 in Cohort C; Student 9 unassigned |
| Student 10 | 13 | 12 | Unassigned (triggers warning banner) |

---

## Running Tests

### Backend Unit Tests

Tests require dev dependencies (phpunit), which are not included in the production Docker images. To run tests via Docker, install dev dependencies first:

```bash
docker compose exec experience-service composer install --dev
docker compose exec experience-service php artisan test

docker compose exec enrolment-service composer install --dev
docker compose exec enrolment-service php artisan test

docker compose exec dashboard-service composer install --dev
docker compose exec dashboard-service php artisan test
```

To run tests locally (requires PHP 8.2 and Composer):

```bash
cd experience-service
composer install
cp .env.testing .env
php artisan test
```

Repeat for `enrolment-service` and `dashboard-service`. The `.env.testing` files are pre-configured with test database credentials (`hatchloom_test`).

### Integration Tests

A PHP integration test script runs against all three services via HTTP, validating endpoints, role-based access, CRUD operations, error handling, and seeded data integrity:

```bash
# With Docker
docker compose exec dashboard-service php integration_test.php

# Locally (services must be running)
php integration_test.php
```

Tests are also run automatically via GitHub Actions on push to `main` and on pull requests.

---

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
| `AUTH_MODE` | http | All | Auth mode: `http` (real JWT via Quebec) or `mock` (static tokens) |
| `USER_SERVICE_URL` | http://localhost:8080 | All | URL for Team Quebec's User Service (JWT auth, when AUTH_MODE=http) |
| `COURSE_SERVICE_URL` | http://localhost:8004 | Experience, Dashboard | URL for Team Papa's Course Service (when AUTH_MODE=http) |
| `CREDENTIAL_SERVICE_URL` | http://localhost:8005 | Enrolment, Dashboard | URL for Karl's Credential Engine (when AUTH_MODE=http) |
| `EXPERIENCE_SERVICE_URL` | http://experience-service:8002 | Dashboard | URL for Experience Service (cross-service calls) |
| `ENROLMENT_SERVICE_URL` | http://enrolment-service:8003 | Dashboard, Experience | URL for Enrolment Service (cross-service calls) |
| `CACHE_STORE` | array | All | Cache driver |
| `SESSION_DRIVER` | array | All | Session driver |
| `QUEUE_CONNECTION` | sync | All | Queue driver (synchronous for D1) |

---

## CI/CD

GitHub Actions runs on push to `main` and on pull requests. The pipeline:

1. Runs PHPUnit tests for each service (with a PostgreSQL service container)
2. Builds all Docker images via `docker compose build`

See `.github/workflows/ci.yml` for details.

---

## Startup Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `curl: (7) Failed to connect ... Connection refused` | Windows defaults to IPv6; Docker binds IPv4 only | Add `-4` flag: `curl -4 http://localhost:...` |
| PowerShell returns HTML or `Invoke-WebRequest` errors | PowerShell aliases `curl` to `Invoke-WebRequest` | Use `curl.exe` instead of `curl` |
| Container shows `unhealthy` or exits | Upstream dependency not ready yet | Wait 30-45s for healthcheck chain to complete, then run `docker compose ps` |
| Code changes not reflected | Source is baked into images at build time | Run `docker compose build` then `docker compose up -d` |
| Port conflict on 8001/8002/8003 | Another process using the port | Stop the conflicting process or edit port mappings in `docker-compose.yml` |

---

## API Contract

**Integrating with Delta?** See [`API-CONTRACT.docx`](API-CONTRACT.docx) for full endpoint documentation, request/response shapes, authentication tokens, data ownership, and integration contracts for each team.

---

## Known Limitations

- **School scoping uses mock data** -- only one school (Ridgewood Academy) is seeded. Multi-tenant isolation is implemented but not tested across multiple schools.
- **No API gateway** -- services call each other directly over the Docker network.
- **Papa and Quebec services must be running** -- when `AUTH_MODE=http`, the Quebec User Service and Papa Course Service must be reachable at the configured URLs. Set `AUTH_MODE=mock` for standalone development.
- **Credential data from Karl** -- `HttpCredentialDataProvider` is implemented and ready, but Karl's Credential Engine endpoints are not yet deployed. When `AUTH_MODE=http` and Karl's service is unavailable, credential data gracefully degrades to empty arrays.

---

## Team Members

**CSSD 2203 (Software Design):** Bhagya, Nathan, Neharika, Shlok

**CSSD 2211 (Cloud Computing):** Bhagya, Miguel, Neharika
