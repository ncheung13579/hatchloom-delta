# Hatchloom Team Delta — School Administration Microservices

Three Laravel microservices powering the School Administration module of the Hatchloom digital learning platform. Manages experiences, cohorts, enrolments, and an aggregated dashboard for school administrators and teachers.

## Architecture

```
              +-----------+   +-----------+   +-------------+
              | Dashboard |   | Experience|   |  Enrolment  |
              |  Service  |   |  Service  |   |   Service   |
              |(port 8001)|   |(port 8002)|   | (port 8003) |
              | Aggregation|  |           |   |             |
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
| Dashboard Service | 8001 | None (aggregation only) | Calls Experience and Enrolment services over HTTP, merges results into a unified dashboard. Returns partial responses with `service_degraded` warnings if a downstream service is unavailable. |
| Experience Service | 8002 | `experiences`, `experience_courses` | Experience CRUD, course assignments, student lists |
| Enrolment Service | 8003 | `cohorts`, `cohort_enrolments` | Cohort lifecycle, student enrolment/removal, CSV export |

Shared tables (`schools`, `users`, `parent_student_links`) are created by whichever service migrates first. All three services connect to one PostgreSQL database.

---

## Quick Start

```bash
git clone <repo-url> hatchloom-delta
cd hatchloom-delta
docker compose up --build -d
```

Services start sequentially via healthchecks: PostgreSQL → Enrolment → Experience → Dashboard. First startup takes ~30-45 seconds while images build. Migrations run automatically.

Verify all services are up:

```bash
curl -4 http://localhost:8001/api/school/dashboard/health
curl -4 http://localhost:8002/api/school/experiences/health
curl -4 http://localhost:8003/api/school/enrolments/health
```

Each returns `{ "status": "ok", "service": "<name>", "timestamp": "..." }`.

**Windows PowerShell users:** Use `curl.exe` instead of `curl` (PowerShell aliases `curl` to `Invoke-WebRequest`). The `-4` flag forces IPv4 — Docker binds to `0.0.0.0`, and Windows often defaults to IPv6 which causes "connection refused" errors.

### Rebuilding After Code Changes

Source code is baked into Docker images at build time:

```bash
docker compose build
docker compose up -d
```

To reset the database:

```bash
docker compose down -v
docker compose up --build -d
```

---

## Authentication

All API requests (except `/health` endpoints) require a valid JWT bearer token from Team Quebec's User Service. Tokens are validated against Quebec's `/auth/validate` endpoint on every request.

```bash
curl -4 -H "Authorization: Bearer <jwt-token>" http://localhost:8001/api/school/dashboard
```

### Roles

| Role | Permissions |
|------|-------------|
| `school_admin` | Unrestricted. Read all screens (dashboard overview, reporting, statistics), create/edit/archive experiences, manage cohorts, enrol/remove students. |
| `school_teacher` | Can create/edit/archive experiences and manage cohorts (create, update, activate, complete) per workpack. Cannot enrol/remove students (admin-only). No access to dashboard overview or reporting screens (Screen 300 is admin-only). Can view individual student drill-downs. Read access to all experience and enrolment data. |
| `student` | Read own enrolments, progress, and credentials only. |
| `parent` | Read linked children's data only. Backend enforces parent-child link verification. |

Experience and cohort write operations return `403` for `student` and `parent` roles. Enrol/remove operations return `403` for all non-admin roles. Dashboard overview, reporting, and widgets return `403` for all non-admin roles. Missing or invalid tokens return `401`.

Parent-child links use the `parent_student_links` join table (columns: `parent_id`, `student_id`). The backend verifies the parent-child relationship before returning any student data.

### Auth Flow (How Delta Validates Tokens)

On every authenticated request, Delta calls Quebec's User Service:

1. **Validate token**: `GET {USER_SERVICE_URL}/auth/validate` with `Authorization: Bearer <token>` header
   - Expected success response (HTTP 200):
     ```json
     { "valid": true, "userId": "<quebec-uuid>", "role": "SCHOOL_TEACHER" }
     ```
   - Any non-200 or `"valid": false` → Delta returns 401

2. **Fetch profile**: `GET {USER_SERVICE_URL}/profile/{userId}` with the same bearer token
   - Expected response:
     ```json
     { "email": "teacher@school.edu", "userId": "<uuid>", "role": "SCHOOL_TEACHER" }
     ```

3. **Local user lookup**: Delta finds the matching user in its `users` table **by email**. If no local user exists with that email, authentication fails.

**Role mapping**: Quebec returns uppercase roles (`SCHOOL_ADMIN`, `SCHOOL_TEACHER`, `STUDENT`, `PARENT`). Delta lowercases them (`school_admin`, `school_teacher`, `student`, `parent`).

**User sync requirement**: Delta's `users` table must be pre-populated with matching email addresses before authentication will work. Delta does not have user registration endpoints — Quebec (or whichever team owns user registration) must seed users into Delta's database directly (e.g., via SQL inserts or a shared migration). See the **Users Table Schema** section below for the required columns.

---

## Cohort Lifecycle

Cohorts follow a one-directional state machine:

```
not_started  ──PATCH /activate──>  active  ──PATCH /complete──>  completed
```

- New cohorts start as `not_started`
- Only `not_started` cohorts can be activated (409 otherwise)
- Only `active` cohorts can be completed (409 otherwise)
- Transitions are irreversible — a completed cohort cannot be reactivated

Students can only be enrolled in `active` cohorts. A student can be enrolled in multiple cohorts across different experiences simultaneously.

### Enrolment Validation Chain

When enrolling a student (`POST /api/school/cohorts/{id}/enrolments`), Delta validates in order:

1. Cohort exists (404 if not)
2. Student exists, belongs to the same school, and has `role='student'` (422 if not)
3. Cohort status is `active` (422 if not)
4. Cohort is not at capacity (422 if full)
5. No existing active enrolment for this student-cohort pair (422 `DUPLICATE_ENROLMENT` if exists)

Removing a student (`DELETE /api/school/cohorts/{id}/enrolments/{studentId}`) is a soft-delete — the enrolment record's status changes to `removed` and `removed_at` is set. The student can be re-enrolled later.

---

## Error Response Format

All error responses use a consistent envelope:

```json
{
  "error": true,
  "message": "Human-readable description",
  "code": "MACHINE_READABLE_CODE"
}
```

Common error codes:

| HTTP Status | Code | When |
|-------------|------|------|
| 401 | `UNAUTHENTICATED` | Missing or invalid bearer token |
| 403 | `FORBIDDEN` | Valid token, insufficient role |
| 404 | `NOT_FOUND` | Resource does not exist |
| 409 | `INVALID_STATE_TRANSITION` | Cohort state transition not allowed |
| 422 | `VALIDATION_ERROR` | Missing or invalid request fields |
| 422 | `DUPLICATE_ENROLMENT` | Student already enrolled in cohort |

---

## External Service Dependencies

Delta calls three external services. When `AUTH_MODE=http`, all providers make real HTTP calls. If an external service is unavailable, affected endpoints degrade gracefully (empty arrays or zero values) rather than failing.

| External Service | What Delta Consumes | Environment Variable |
|-----------------|---------------------|----------------------|
| **Quebec User Service** | JWT token validation on every request, venture/LaunchPad counts for dashboard | `USER_SERVICE_URL` |
| **Papa Course Service** | Course catalogue for experience creation, block data for content views, student progress/credit data | `COURSE_SERVICE_URL` |
| **Karl's Credential Engine** | Badges, certificates, and curriculum (PoS) coverage mapping for student drill-downs | `CREDENTIAL_SERVICE_URL` |

### What Delta Calls on Each External Service

**Quebec User Service** (`USER_SERVICE_URL`):

| Method | Endpoint | Purpose | Expected Response |
|--------|----------|---------|-------------------|
| GET | `/auth/validate` | JWT validation (every request) | `{ "valid": true, "userId": "<uuid>", "role": "SCHOOL_TEACHER" }` |
| GET | `/profile/{userId}` | Fetch user email for local lookup | `{ "email": "...", "userId": "...", "role": "..." }` |
| GET | `/profile?page=0&size=100` | Dashboard KPI: count active ventures | `{ "content": [{ "email": "...", "role": "STUDENT", "activeVentures": 3 }], "last": true }` |

**Papa Course Service** (`COURSE_SERVICE_URL`):

| Method | Endpoint | Purpose | Expected Response |
|--------|----------|---------|-------------------|
| GET | `/api/courses` | Full course catalogue | `{ "data": [{ "id": 1, "name": "...", ... }] }` |
| GET | `/api/courses?ids[]=1&ids[]=2` | Batch course lookup by IDs | `{ "data": [{ "id": 1, "name": "...", ... }] }` |
| GET | `/api/courses/{id}` | Single course detail | `{ "id": 1, "name": "...", ... }` |
| GET | `/api/progress/problems-tackled?experience_ids[]=1` | KPI: problems tackled count | `{ "count": 42 }` |
| GET | `/api/progress/credit-progress?experience_ids[]=1` | KPI: credit progress | `{ "progress": 0.75 }` |
| GET | `/api/progress/timely-completion?total_enrolled=10&assigned=8` | KPI: timely completion rate | `{ "rate": 0.85 }` |
| POST | `/api/progress/pos-coverage` | Curriculum coverage reporting | Body: `{ "students": [{ "id": 4, "name": "..." }] }` |
| POST | `/api/progress/engagement` | Engagement rate reporting | Body: `{ "students": [{ "id": 4, "name": "..." }] }` |

**Karl's Credential Engine** (`CREDENTIAL_SERVICE_URL`):

| Method | Endpoint | Purpose | Expected Response |
|--------|----------|---------|-------------------|
| GET | `/api/credentials/students/{studentId}/summary` | Student badges and certificates | `{ "total_earned": 3, "in_progress": 1, "details": [{ "id": 1, "name": "...", "type": "badge", "status": "earned", "earned_at": "2026-03-15" }] }` |

If any external service is unreachable, Delta returns zero values or empty arrays for that data — no 500 errors.

### Cross-Service Communication (Internal)

The Dashboard Service has no database tables of its own. It aggregates data by calling the other two Delta services over HTTP:

- **Dashboard → Experience Service**: experience list, course contents, statistics
- **Dashboard → Enrolment Service**: cohort list, enrolment statistics, student details
- **Experience → Enrolment Service**: cohort data for experience detail views

If a downstream service is unreachable, the Dashboard returns a partial response with a `service_degraded` warning flag rather than a 500 error.

---

## Database Tables

All three services share one PostgreSQL database. Tables and ownership:

| Table | Owner | Description |
|-------|-------|-------------|
| `schools` | Shared | School records |
| `users` | Shared | All user accounts (admins, teachers, students, parents, platform staff) |
| `parent_student_links` | Shared | Many-to-many parent-child relationships |
| `experiences` | Experience Service | Learning experiences |
| `experience_courses` | Experience Service | Course assignments within experiences (references Papa course IDs) |
| `cohorts` | Enrolment Service | Student groups within an experience, with lifecycle state |
| `cohort_enrolments` | Enrolment Service | Student-to-cohort assignments |

The database starts empty. Users and schools must be seeded by the integrating team (see **Users Table Schema** below). Experiences, cohorts, and enrolments are created through Delta's API endpoints. Course data comes from Papa's service at runtime.

### Users Table Schema

Since Quebec manages user registration, the integrating team must ensure Delta's `users` table is populated. Required columns:

| Column | Type | Required | Notes |
|--------|------|----------|-------|
| `id` | bigint (PK) | Auto | Auto-incremented |
| `name` | varchar(255) | Yes | Display name |
| `email` | varchar(255) | Yes | **Unique** — Delta matches auth tokens to local users by email |
| `password` | varchar(255) | Yes | Can be a placeholder hash; not used when `AUTH_MODE=http` |
| `role` | varchar(20) | Yes | `school_admin`, `school_teacher`, `student`, `parent` |
| `school_id` | bigint (FK) | Nullable | References `schools.id`. Null for platform-level staff. |
| `grade` | smallint | Nullable | Student grade level (e.g., `10`) |
| `created_at` | timestamp | Yes | |
| `updated_at` | timestamp | Yes | |

A `schools` record must exist before creating users that reference it.

---

## API Endpoints

### Dashboard Service (Port 8001)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/school/dashboard` | Admin only | Full dashboard overview with KPIs |
| GET | `/api/school/dashboard/students/{id}` | All roles (scoped) | Student drill-down with progress, credentials, curriculum mapping |
| GET | `/api/school/dashboard/widgets` | Admin only | All dashboard widgets |
| GET | `/api/school/dashboard/widgets/{type}` | Admin only | Single widget (`cohort_summary`, `student_table`, `engagement_chart`) |
| GET | `/api/school/dashboard/reporting/pos-coverage` | Admin only | Curriculum coverage reporting |
| GET | `/api/school/dashboard/reporting/engagement` | Admin only | Engagement rates |
| GET | `/api/school/dashboard/health` | Public | Health check |

### Experience Service (Port 8002)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/school/experiences` | All roles | List experiences |
| POST | `/api/school/experiences` | Admin/Teacher | Create experience |
| GET | `/api/school/experiences/{id}` | All roles | Experience detail |
| PUT | `/api/school/experiences/{id}` | Admin/Teacher | Update experience |
| DELETE | `/api/school/experiences/{id}` | Admin/Teacher | Archive experience |
| GET | `/api/school/experiences/{id}/students` | All roles (scoped) | Paginated student list |
| GET | `/api/school/experiences/{id}/students/{studentId}` | All roles (scoped) | Student detail within experience |
| GET | `/api/school/experiences/{id}/students/export` | Admin/Teacher | CSV export of students |
| GET | `/api/school/experiences/{id}/contents` | All roles | Course blocks and contents |
| GET | `/api/school/experiences/{id}/statistics` | Admin/Teacher | Enrolment/completion stats |
| GET | `/api/school/courses` | Admin/Teacher | Course catalogue |
| GET | `/api/school/experiences/health` | Public | Health check |

### Enrolment Service (Port 8003)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/school/cohorts` | All roles | List cohorts (filterable by `experience_id`, `status`) |
| POST | `/api/school/cohorts` | Admin/Teacher | Create cohort |
| GET | `/api/school/cohorts/{id}` | All roles | Cohort detail |
| PUT | `/api/school/cohorts/{id}` | Admin/Teacher | Update cohort |
| PATCH | `/api/school/cohorts/{id}/activate` | Admin/Teacher | Activate cohort (`not_started` → `active`) |
| PATCH | `/api/school/cohorts/{id}/complete` | Admin/Teacher | Complete cohort (`active` → `completed`) |
| POST | `/api/school/cohorts/{id}/enrolments` | Admin only | Enrol student in cohort |
| DELETE | `/api/school/cohorts/{id}/enrolments/{studentId}` | Admin only | Remove student |
| GET | `/api/school/enrolments` | All roles | Paginated enrolment overview |
| GET | `/api/school/enrolments/statistics` | Admin/Teacher | Aggregate stats and warnings |
| GET | `/api/school/enrolments/export` | Admin/Teacher | CSV export (filterable by `cohort_id`, `experience_id`) |
| GET | `/api/school/enrolments/students/{id}` | All roles (scoped) | Student enrolment detail with credentials |
| GET | `/api/school/enrolments/health` | Public | Health check |

For full request/response shapes, see [`API-CONTRACT.docx`](API-CONTRACT.docx).

### Request Body Schemas

**POST /api/school/experiences** (create experience):

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | string | Yes | Max 255 chars, non-empty |
| `description` | string | Yes | Max 5000 chars |
| `course_ids` | int[] | Yes | At least 1 element; each ID validated against Papa's course catalogue |

**PUT /api/school/experiences/{id}** (update experience):

All fields optional. Only provided fields are updated. `course_ids` replaces the entire list if present.

**POST /api/school/cohorts** (create cohort):

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `experience_id` | int | Yes | Must reference an existing experience |
| `name` | string | Yes | Max 255 chars, non-empty |
| `start_date` | date | Yes | Today or later (`Y-m-d` format) |
| `end_date` | date | Yes | Must be after `start_date` |
| `capacity` | int | No | 1–10000 if provided |
| `teacher_id` | int | No | Must reference an existing user if provided |

New cohorts are always created with `status='not_started'`.

**PUT /api/school/cohorts/{id}** (update cohort):

All fields optional except `experience_id` and `status` (not updatable via PUT — use PATCH /activate and /complete for status changes).

**POST /api/school/cohorts/{id}/enrolments** (enrol student):

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `student_id` | int | Yes | Must exist, same school, role=student, cohort must be active |

### Response Format

**List endpoints** return a paginated wrapper:

```json
{
  "data": [ ... ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 67
  }
}
```

**Detail and create endpoints** return the resource directly (no wrapper):

```json
{
  "id": 1,
  "name": "Cohort A",
  "experience_id": 1,
  "status": "active",
  "teacher_name": "Ms. Smith",
  "student_count": 6,
  "removed_count": 0,
  "capacity": 25,
  "start_date": "2026-02-01",
  "end_date": "2026-06-01"
}
```

### Query Parameters

**GET /api/school/cohorts**:

| Param | Type | Description |
|-------|------|-------------|
| `experience_id` | int | Filter to a single experience |
| `status` | string | Filter by status: `not_started`, `active`, `completed` |
| `search` | string | Case-insensitive substring match on cohort name |

**GET /api/school/experiences**:

| Param | Type | Description |
|-------|------|-------------|
| `search` | string | Case-insensitive substring match on experience name |
| `per_page` | int | Page size (default 15, max 100) |

**GET /api/school/enrolments**:

| Param | Type | Description |
|-------|------|-------------|
| `search` | string | Name search on students |
| `experience_id` | int | Filter to cohorts in a specific experience |
| `cohort_id` | int | Filter to a specific cohort |
| `student_id` | int | Filter to a specific student (auto-set for student/parent roles) |
| `per_page` | int | Page size (default 15, max 100) |

**GET /api/school/enrolments/export**:

| Param | Type | Description |
|-------|------|-------------|
| `cohort_id` | int | Export only enrolments from this cohort |
| `experience_id` | int | Export only enrolments from cohorts in this experience |

### CSV Export Columns

**Enrolment export** (`/api/school/enrolments/export`):
`student_name`, `student_email`, `cohort_name`, `experience_name`, `status`, `enrolled_at`, `removed_at`

**Experience student export** (`/api/school/experiences/{id}/students/export`):
`student_name`, `student_email`, `cohort_name`, `status`, `enrolled_at`

All timestamps are in ISO 8601 format. Exports include both active and removed enrolments.

---

## Standalone Development (AUTH_MODE=mock)

For development without external services, set `AUTH_MODE=mock` in `docker-compose.yml` for all three services. This switches authentication to static bearer tokens and replaces all external HTTP calls with mock data providers.

Mock tokens when `AUTH_MODE=mock`:

| Token | Role |
|-------|------|
| `test-admin-token` | `school_admin` |
| `test-teacher-token` | `school_teacher` |
| `test-student-token` | `student` |
| `test-parent-token` | `parent` |

```bash
curl -4 -H "Authorization: Bearer test-admin-token" http://localhost:8001/api/school/dashboard
```

Mock providers return realistic static data demonstrating the correct response structures. Switching back to `AUTH_MODE=http` requires no code changes — only the environment variable.

---

## Local Development (Without Docker)

Each service is an independent Laravel application:

```bash
cd experience-service
composer install
cp .env.example .env
# Edit .env: set DB_HOST=localhost (default is "postgres", the Docker hostname)
php artisan key:generate
php artisan migrate
php artisan serve --port=8002
```

Repeat for `enrolment-service` (port 8003) and `dashboard-service` (port 8001).

For cross-service HTTP calls to work locally, set these in each service's `.env`:

```
# Dashboard Service
EXPERIENCE_SERVICE_URL=http://localhost:8002
ENROLMENT_SERVICE_URL=http://localhost:8003

# Experience Service
ENROLMENT_SERVICE_URL=http://localhost:8003
```

---

## Environment Variables

| Variable | Default | Used By | Description |
|----------|---------|---------|-------------|
| `AUTH_MODE` | `http` | All | Authentication mode. Use `http` for production (JWT via Quebec). |
| `USER_SERVICE_URL` | `http://localhost:8080` | All | Team Quebec's User Service (JWT validation) |
| `COURSE_SERVICE_URL` | `http://localhost:8004` | Experience, Dashboard | Team Papa's Course Service |
| `CREDENTIAL_SERVICE_URL` | `http://localhost:8005` | Enrolment, Dashboard | Karl's Credential Engine |
| `EXPERIENCE_SERVICE_URL` | `http://experience-service:8002` | Dashboard | Internal: Experience Service URL |
| `ENROLMENT_SERVICE_URL` | `http://enrolment-service:8003` | Dashboard, Experience | Internal: Enrolment Service URL |
| `DB_HOST` | `postgres` | All | Database host (`postgres` is the Docker service name) |
| `DB_PORT` | `5432` | All | Database port |
| `DB_DATABASE` | `hatchloom` | All | Database name |
| `DB_USERNAME` | `hatchloom` | All | Database user |
| `DB_PASSWORD` | `secret` | All | Database password — **change for production** |

All variables can be overridden in `docker-compose.yml` or via a `.env` file.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `Connection refused` on curl | Windows defaults to IPv6; Docker binds IPv4 only | Add `-4` flag: `curl -4 http://localhost:...` |
| PowerShell returns HTML or errors | PowerShell aliases `curl` to `Invoke-WebRequest` | Use `curl.exe` instead of `curl` |
| Container shows `unhealthy` | Upstream dependency not ready yet | Wait 30-45s for healthcheck chain, then `docker compose ps` |
| Code changes not reflected | Source baked into images at build | `docker compose build && docker compose up -d` |
| Port conflict on 8001/8002/8003 | Another process using the port | Stop the conflicting process or edit ports in `docker-compose.yml` |
| Dashboard returns partial data with warnings | A downstream service is unreachable | Check that Experience and Enrolment services are healthy |
