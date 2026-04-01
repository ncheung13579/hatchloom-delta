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
| `school_admin` | Read all screens. Enrol/remove students. Cannot create/edit experiences or cohorts. |
| `school_teacher` | Full write access: create/edit experiences, manage cohorts, enrol/remove students. |
| `student` | Read own enrolments, progress, and credentials only. |
| `parent` | Read linked children's data only. Backend enforces parent-child link verification. |

Teacher-only actions return `403` for all other roles. Missing or invalid tokens return `401`.

---

## API Endpoints

### Dashboard Service (Port 8001)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/school/dashboard` | Admin/Teacher | Full dashboard overview with KPIs |
| GET | `/api/school/dashboard/students/{id}` | All roles (scoped) | Student drill-down with progress, credentials, curriculum mapping |
| GET | `/api/school/dashboard/widgets` | Admin/Teacher | All dashboard widgets |
| GET | `/api/school/dashboard/widgets/{type}` | Admin/Teacher | Single widget (`cohort_summary`, `student_table`, `engagement_chart`) |
| GET | `/api/school/dashboard/reporting/pos-coverage` | Admin/Teacher | Curriculum coverage reporting |
| GET | `/api/school/dashboard/reporting/engagement` | Admin/Teacher | Engagement rates |
| GET | `/api/school/dashboard/health` | Public | Health check |

### Experience Service (Port 8002)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/school/experiences` | All roles | List experiences |
| POST | `/api/school/experiences` | Teacher only | Create experience |
| GET | `/api/school/experiences/{id}` | All roles | Experience detail |
| PUT | `/api/school/experiences/{id}` | Teacher only | Update experience |
| DELETE | `/api/school/experiences/{id}` | Teacher only | Archive experience |
| GET | `/api/school/experiences/{id}/students` | Admin/Teacher | Paginated student list |
| GET | `/api/school/experiences/{id}/students/{studentId}` | Admin/Teacher | Student detail within experience |
| GET | `/api/school/experiences/{id}/students/export` | Admin/Teacher | CSV export of students |
| GET | `/api/school/experiences/{id}/contents` | All roles | Course blocks and contents |
| GET | `/api/school/experiences/{id}/statistics` | Admin/Teacher | Enrolment/completion stats |
| GET | `/api/school/courses` | All roles | Course catalogue |
| GET | `/api/school/experiences/health` | Public | Health check |

### Enrolment Service (Port 8003)

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/school/cohorts` | All roles | List cohorts (filterable by `experience_id`, `status`) |
| POST | `/api/school/cohorts` | Teacher only | Create cohort |
| GET | `/api/school/cohorts/{id}` | All roles | Cohort detail |
| PUT | `/api/school/cohorts/{id}` | Teacher only | Update cohort |
| PATCH | `/api/school/cohorts/{id}/activate` | Teacher only | Activate cohort (`not_started` → `active`) |
| PATCH | `/api/school/cohorts/{id}/complete` | Teacher only | Complete cohort (`active` → `completed`) |
| POST | `/api/school/cohorts/{id}/enrolments` | Admin/Teacher | Enrol student in cohort |
| DELETE | `/api/school/cohorts/{id}/enrolments/{studentId}` | Admin/Teacher | Remove student |
| GET | `/api/school/enrolments` | All roles | Paginated enrolment overview |
| GET | `/api/school/enrolments/statistics` | Admin/Teacher | Aggregate stats and warnings |
| GET | `/api/school/enrolments/export` | Admin/Teacher | CSV export (filterable by `cohort_id`, `experience_id`) |
| GET | `/api/school/enrolments/students/{id}` | All roles (scoped) | Student enrolment detail with credentials |
| GET | `/api/school/enrolments/health` | Public | Health check |

For full request/response shapes, see [`API-CONTRACT.docx`](API-CONTRACT.docx).

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
