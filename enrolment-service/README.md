# Enrolment Service

**Port:** 8003 | **Tables owned:** `cohorts`, `cohort_enrolments`

## Purpose

The Enrolment Service manages the Cohort and Enrolment domain for Screen 303. A Cohort is a live running instance of an Experience with a start date, end date, capacity, and enrolled students.

- **Screen 303 (Enrolment):** Student enrolment and assignment tracking, cohort lifecycle management, statistics, warnings, and CSV export

---

## Setup Instructions

### Option A — Docker (recommended)

Run the full Delta stack from the repo root:

```bash
git clone <repo-url> hatchloom-delta
cd hatchloom-delta
docker compose up --build -d
```

Services start sequentially via healthchecks: PostgreSQL → Enrolment → Experience → Dashboard. First startup takes ~30–45 seconds while images build. Migrations run automatically via `docker-entrypoint.sh`.

Verify the service is up:

```bash
curl -4 http://localhost:8003/api/school/enrolments/health
```

Expected response:
```json
{ "status": "ok", "service": "enrolment", "timestamp": "...", "database": "connected" }
```

> **Windows PowerShell users:** Use `curl.exe` instead of `curl`. Add `-4` to force IPv4 — Docker binds to `0.0.0.0` and Windows often defaults to IPv6, causing "connection refused" errors.

### Option B — Local (without Docker)

Requires PostgreSQL 16 running locally.

```bash
cd enrolment-service
composer install
cp .env.example .env
# Edit .env: set DB_HOST=localhost, DB_DATABASE, DB_USERNAME, DB_PASSWORD
php artisan key:generate
php artisan migrate
php artisan serve --port=8003
```

---

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `APP_KEY` | *(required)* | Laravel app key — generate with `php artisan key:generate` |
| `APP_ENV` | `local` | Environment (`local`, `production`) |
| `APP_DEBUG` | `true` | Enable debug mode (set to `false` in production) |
| `DB_CONNECTION` | `pgsql` | Database driver |
| `DB_HOST` | `postgres` | Database host (`postgres` is the Docker service name; use `localhost` for local dev) |
| `DB_PORT` | `5432` | Database port |
| `DB_DATABASE` | `hatchloom` | Database name |
| `DB_USERNAME` | `hatchloom` | Database user |
| `DB_PASSWORD` | `secret` | Database password — change for production |
| `AUTH_MODE` | `http` | Authentication mode. `http` = real JWT via Quebec. `mock` = static test tokens |
| `USER_SERVICE_URL` | `http://localhost:8080` | Team Quebec's User Service (JWT validation on every request) |
| `CREDENTIAL_SERVICE_URL` | `http://localhost:8005` | Karl's Credential Engine (student credential summaries) |
| `CACHE_STORE` | `array` | Cache driver |
| `SESSION_DRIVER` | `array` | Session driver |
| `QUEUE_CONNECTION` | `sync` | Queue driver |

---

## How to Run Tests

### Via Docker

```bash
docker compose exec enrolment-service php artisan test
```

### Locally

```bash
cd enrolment-service
composer install
cp .env.example .env
# Edit .env: set DB_* variables to point at your local PostgreSQL
php artisan key:generate
php artisan migrate
vendor/bin/phpunit
```

### Test tokens (AUTH_MODE=mock)

| Token | Role |
|---|---|
| `test-admin-token` | `school_admin` |
| `test-teacher-token` | `school_teacher` |
| `test-student-token` | `student` |
| `test-parent-token` | `parent` |

---

## Endpoints

All authenticated endpoints require `Authorization: Bearer {token}`.

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/school/enrolments/health` | Public | Health check |
| GET | `/api/school/cohorts` | All roles | List cohorts (`?experience_id=`, `?status=`, `?search=`) |
| POST | `/api/school/cohorts` | Admin, Teacher | Create a new cohort |
| GET | `/api/school/cohorts/{id}` | All roles | Get cohort detail |
| PUT | `/api/school/cohorts/{id}` | Admin, Teacher | Update a cohort (name, dates, capacity) |
| PATCH | `/api/school/cohorts/{id}/activate` | Admin, Teacher | Transition from `not_started` to `active` |
| PATCH | `/api/school/cohorts/{id}/complete` | Admin, Teacher | Transition from `active` to `completed` |
| POST | `/api/school/cohorts/{id}/enrolments` | Admin only | Enrol a student (`{ "student_id": 10 }`) |
| DELETE | `/api/school/cohorts/{id}/enrolments/{studentId}` | Admin only | Remove a student (soft delete) |
| GET | `/api/school/enrolments` | All roles | School-wide enrolment overview (`?search=`, `?experience_id=`, `?cohort_id=`, `?grade=`) |
| GET | `/api/school/enrolments/students/{studentId}` | All roles (scoped) | Student drill-down |
| GET | `/api/school/enrolments/statistics` | Admin, Teacher | Aggregate stats with warnings |
| GET | `/api/school/enrolments/export` | Admin, Teacher | Export enrolments as CSV |

For full request/response schemas and examples, see `API-DOCS-ENROLMENT.md`.

---

## Database Migrations

Migrations run automatically on Docker startup. To run manually:

```bash
php artisan migrate
```

Tables created by this service:

| Table | Description |
|---|---|
| `cohorts` | Cohort records with lifecycle state, capacity, dates, and teacher assignment |
| `cohort_enrolments` | Student-to-cohort assignments with soft-delete (status + removed_at) |

Shared tables created on first migration (whichever service runs first):

| Table | Description |
|---|---|
| `schools` | School records |
| `users` | All user accounts (admins, teachers, students, parents) |
| `parent_student_links` | Many-to-many parent-child relationships |
| `experiences` | Learning experience records (owned by Experience Service) |

---

## Design Patterns

### State Pattern — Cohort Lifecycle

Cohort status follows a strict one-directional lifecycle. Invalid transitions are rejected with HTTP 409.

```
not_started  -->  active  -->  completed
```

Implemented with `CohortState` interface and three concrete state classes: `NotStartedState`, `ActiveState`, `CompletedState`.

### Observer Pattern — Enrolment Events

Laravel events are dispatched when students are enrolled (`StudentEnrolled`) or removed (`StudentRemoved`). Listeners handle dashboard count updates, teacher notifications, and credential checks.

### Strategy Pattern — Credential Provider

`CredentialDataProviderInterface` is injected into `EnrolmentService`. Controlled by `AUTH_MODE`:
- `mock` → `MockCredentialDataProvider` (returns placeholder data)
- `http` → `HttpCredentialDataProvider` (calls Karl's Credential Engine)

### Soft Deletes — Enrolments

Students are never hard-deleted from cohorts. Removal sets `status = 'removed'` and records a `removed_at` timestamp, preserving the full audit trail for CSV export and reporting.

---

## Upstream API Contracts

This service calls the following external endpoints. Set the corresponding environment variables when those services are available.

### Quebec User Service (`USER_SERVICE_URL`)

Called on **every authenticated request** for JWT validation.

| Method | Endpoint | Purpose | Expected Response |
|---|---|---|---|
| GET | `/auth/validate` | Validate bearer token | `{ "valid": true, "userId": "<uuid>", "role": "SCHOOL_ADMIN" }` |
| GET | `/profile/{userId}` | Fetch user email for local lookup | `{ "email": "...", "userId": "...", "role": "..." }` |

Notes:
- Quebec returns uppercase roles (`SCHOOL_ADMIN`, `SCHOOL_TEACHER`, `STUDENT`, `PARENT`). This service lowercases them.
- Delta's `users` table must be pre-populated with matching email addresses before authentication will work. Delta does not have user registration endpoints.
- Any non-200 or `"valid": false` response causes Delta to return 401.

### Karl's Credential Engine (`CREDENTIAL_SERVICE_URL`)

Called by the student drill-down endpoint (`GET /api/school/enrolments/students/{id}`).

| Method | Endpoint | Purpose | Expected Response |
|---|---|---|---|
| GET | `/api/credentials/students/{studentId}/summary` | Student badge and certificate summary | `{ "total_earned": 2, "in_progress": 1, "details": [...] }` |

Notes:
- If unreachable, returns `{ "total_earned": 0, "in_progress": 0, "details": [] }` — no 500 error.
- The bearer token from the original request is forwarded to Karl's API.

---

## Known Issues

### External Services Not Wired in docker-compose.yml

`USER_SERVICE_URL`, and `CREDENTIAL_SERVICE_URL` are not set in `docker-compose.yml` because those services don't run inside Delta's Docker stack. They default to `localhost:8080` and `localhost:8005` respectively. Set these env vars in `docker-compose.yml` when the external services are deployed.

### Credential Data is Mocked

When `AUTH_MODE=mock`, `MockCredentialDataProvider` returns hardcoded placeholder credential data (2 earned, 1 in progress). This is by design for development. Switch to `AUTH_MODE=http` and set `CREDENTIAL_SERVICE_URL` to get real credential data from Karl's engine.

### Grade Filtering Not Yet Applied

The `GET /api/school/enrolments?grade=10` query parameter is accepted but not yet filtered — it is a no-op. The `users` table has the `grade` column, but the filter logic in `EnrolmentService::getEnrolmentOverview()` is commented out pending confirmation of the column's data type and expected values.

### Observer Pattern Listeners are Stubs

The three event listeners (`UpdateDashboardCounts`, `NotifyTeacher`, `TriggerCredentialCheck`) log messages but do not yet perform real actions. Full implementation requires integration with the Dashboard Service and Karl's Credential Engine.
