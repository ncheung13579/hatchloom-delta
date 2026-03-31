# Hatchloom Team Delta -- School Administration Microservices

Team Delta's School Administration backend and frontend for the Hatchloom digital learning platform. Hatchloom is a learning and community platform for teens aged 12-17, built across four York University student teams. Team Delta owns the School Administration module (Screens 300-303), which school administrators use to manage experiences, cohorts, enrolments, and view dashboards.

This project is built as three independent Laravel microservices plus a React frontend for the CSSD 2211 (Cloud Computing) and CSSD 2203 (Software Design) deliverables.

## What We Built

- **Screen 300 -- School Admin Dashboard**: Aggregated KPIs (Problems Tackled, Active Ventures, Students, Experiences, Credit Progress, Timely Completion), warning banners for unassigned students, tabbed Students/Cohorts tables, engagement metrics, and student drill-down with progress, credentials, and curriculum mapping
- **Screen 301 -- Experiences Dashboard**: Searchable table of learning experiences with status, course contents, and cohort links. Create, edit, and archive experiences. Assign courses from Team Papa's catalogue.
- **Screen 302 -- Experience Detail**: Breadcrumb navigation, metric cards, Content & Delivery section with courses and block counts, cohort management (create, activate, complete), paginated student table with CSV export, and individual student drill-down
- **Screen 303 -- Enrolment**: School-wide enrolment overview with grade/experience/cohort filters, metric cards, attention banners for unassigned students, enrol/remove students, CSV export, and student detail view with credentials

Read-only views are provided for **students** (personal dashboard with their own enrolments and progress) and **parents** (linked children's data only, enforced by backend).

---

## Architecture

```
                    +---------------------------------+
                    |      React Frontend (Vite)      |
                    |          (port 3000)            |
                    |   Screens 300, 301, 302, 303    |
                    +-----+--------+--------+---------+
                          |        |        |
                     /dashboard /experiences /enrolments
                          |    /courses     /cohorts
                          |        |        |
              +-----------v--+ +---v--------+ +v--------------+
              | Dashboard    | | Experience | |  Enrolment    |
              |  Service     | |  Service   | |   Service     |
              | (port 8001)  | |(port 8002) | | (port 8003)   |
              | Screen 300   | |Screens     | |  Screen 303   |
              | Aggregation  | |301-302     | |               |
              +------+-------+ +-----+------+ +------+--------+
                     |               |               |
                     +---------------+---------------+
                                     |
                            +--------v---------+
                            |  PostgreSQL 16   |
                            |   (port 5432)    |
                            | Shared database  |
                            +------------------+
```

| Service | Port | Owns Tables | Description |
|---------|------|-------------|-------------|
| Frontend | 3000 | None | React SPA with Vite, Tailwind CSS, TanStack Query |
| Dashboard Service | 8001 | None (aggregation only) | School Admin Dashboard (Screen 300). Calls Experience and Enrolment services over HTTP. |
| Experience Service | 8002 | `experiences`, `experience_courses` | Experience management (Screens 301, 302) |
| Enrolment Service | 8003 | `cohorts`, `cohort_enrolments` | Cohort and enrolment management (Screen 303) |

All three backend services connect to a single shared PostgreSQL database. Each service runs its own migrations for its owned tables. The `schools` and `users` tables are seeded as reference data.

**Key architectural decisions:**

- The **Dashboard Service owns no database tables**. It calls the other two services over HTTP and merges the results. If one downstream service is down, it still returns a partial response with a `service_degraded` warning (graceful degradation).
- The **Experience Service** owns experiences and their course assignments.
- The **Enrolment Service** owns cohorts and student enrolments, including the cohort state machine.
- External team data (courses from Papa, auth from Quebec, credentials from Karl) is provided by **strategy-pattern providers** with both mock and HTTP implementations.

---

## Prerequisites

- **Docker** and **Docker Compose** (required for containerized deployment)
- **Node.js 20+** and **npm 10+** (for frontend local development)
- **PHP 8.2** and **Composer** (for backend local development without Docker)
- **PostgreSQL 16** (for backend local development without Docker)

---

## Quick Start (Docker -- Full Stack)

```bash
# Clone the repository
git clone <repo-url> hatchloom-delta
cd hatchloom-delta

# Build and start all services including frontend
docker compose up --build -d
```

Services start sequentially via healthchecks: PostgreSQL -> Enrolment Service -> Experience Service -> Dashboard Service -> Frontend. Each backend service automatically runs migrations and seeds test data. First startup takes approximately 60-90 seconds while images build.

Check that all containers are running:

```bash
docker compose ps
```

Then open **http://localhost:3000** in your browser. Click **School Admin** or **Teacher** to log in and start exploring Screens 300-303.

To verify backend health individually:

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

## Quick Start (Local Development -- Frontend + Docker Backend)

This is the ideal workflow for frontend development. Run the backend services in Docker and the frontend locally with hot reload:

```bash
# Start backend services
docker compose up --build -d postgres enrolment-service experience-service dashboard-service

# Wait for backends to become healthy (~30s)
docker compose ps

# Start frontend with hot reload
cd frontend
npm install
npm run dev
```

Open **http://localhost:3000**. Vite proxies all `/api` requests to the backend Docker services automatically:

| Path prefix | Target |
|-------------|--------|
| `/api/school/dashboard` | http://localhost:8001 |
| `/api/school/experiences`, `/api/school/courses` | http://localhost:8002 |
| `/api/school/cohorts`, `/api/school/enrolments` | http://localhost:8003 |

---

## Local Development (Without Docker)

Each backend service is an independent Laravel application. To run one locally:

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

Then start the frontend:

```bash
cd frontend
npm install
npm run dev
```

---

## Authentication and Roles

The system supports two authentication modes, toggled by the `AUTH_MODE` environment variable in `docker-compose.yml`:

- **`AUTH_MODE=mock`**: Static token-to-user mapping via `MockAuthMiddleware`. No external service required. Used for standalone development, testing, and grading.
- **`AUTH_MODE=http`**: Real authentication via Team Quebec's User Service. JWT bearer tokens are validated against Quebec's `/auth/validate` endpoint. This is the production mode.

The toggle is configured in each service's `AppServiceProvider` and `bootstrap/app.php`. Changing `AUTH_MODE` also switches all other strategy-pattern providers (course data, credentials, etc.) between mock and HTTP implementations.

### Login

Open http://localhost:3000. When using `AUTH_MODE=mock`, you will see role buttons on the login page:

| Button | Role | User | What They See |
|--------|------|------|---------------|
| **School Admin** | `school_admin` | Admin User (id=1) | Dashboard, Experiences, Enrolments -- **read-only** for experience/cohort content, can manage student enrolments |
| **Teacher** | `school_teacher` | Ms. Smith (id=2) | Same screens -- **full write access**: create/edit experiences, create/edit/activate/complete cohorts, enrol/remove students |
| **Student** | `student` | Student 1 (id=4) | Personal dashboard with own enrolments, progress, and credentials. Read-only. |
| **Parent** | `parent` | Parent User (id=14) | Dashboard showing linked children's data only (children: student ids 4 and 5). Cannot see other students. |

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

### Role-Based Access Control

Two enforcement layers:

- **Backend**: Controller middleware checks the user's role. Teacher-only actions (create/edit/delete experience, create/edit/activate/complete cohort) return 403 for admins, students, and parents. Admin + Teacher can both enrol/remove students.
- **Frontend**: Uses `useAuth()` to check the role and hides buttons the user cannot use, so the user never encounters an error.

Parent-child links use a `parent_student_links` join table. The backend verifies the parent actually owns the child before returning data. A parent cannot pass an arbitrary student ID.

---

## Screens

### Screen 300 -- Dashboard

**URL:** `/admin/dashboard`

Overview with 6 KPI metric cards, warning banners for unassigned students, and tabbed Students/Cohorts tables. The Students tab shows status dots (green = on track, amber = at risk, gray = not assigned) and drill-down arrows. The Cohorts tab shows all cohorts with status pills, student counts, and end dates. Below the tabs, the Engagement widget shows login frequency, completion rates, and engagement levels.

Click any student's arrow to drill into their detail page showing progress metrics, course progress bars, cohort assignments, LaunchPad ventures, credentials, and Alberta Program of Studies curriculum mapping.

### Screen 301 -- Experiences

**URL:** `/admin/experiences`

Searchable table of all experiences with name, grade, status pill, coordinator, course count, credit total, and cohort links. The search bar uses debounced filtering (400ms). Teachers can create new experiences via a modal with a course picker (courses from Team Papa's catalogue).

### Screen 302 -- Experience Detail

**URL:** `/admin/experiences/{id}`

Breadcrumb navigation (Experiences > Experience Name), status pill, and 3 metric cards (Total Cohorts, Enrolled Students, Completion Rate). Content & Delivery section shows courses with block counts. Cohorts table with create/activate/complete actions. Paginated student table with search and CSV export. Click a student to drill down.

### Screen 303 -- Enrolment

**URL:** `/admin/enrolments`

School-wide enrolment overview. 3 metric cards (Students Enrolled, Active Assignments, Not in Any Active Cohort), attention banner for unassigned students, filter bar (grade, experience, cohort), searchable student table with cohort pill badges, remove action with confirmation modal, and CSV export.

---

## Demo Walkthrough

### Teacher Flow (primary demo)

1. Log in as **Teacher**
2. **Dashboard**: View 6 KPI cards, warning banner, Students/Cohorts tabs, engagement widget. Click a student arrow for drill-down.
3. **Experiences**: Search experiences. Click "Create Experience" -- fill in name, description, select courses, submit.
4. **Experience Detail**: Click an experience. View metric cards, contents, cohorts. Click "Create Cohort" -- fill in name, dates, capacity.
5. **Cohort Detail**: Click a cohort. Demonstrate the **State pattern**: click "Activate Cohort" (status -> Active), then "Complete Cohort" (status -> Completed). Notice buttons disappear -- completed is a terminal state.
6. **Enrol Student**: On an active cohort, click "Enrol Student", enter a student ID, submit. The student appears in the table immediately.
7. **Student Drill-Down**: Click a student arrow. View progress, ventures (from Quebec's LaunchPad), credentials (from Karl's engine), and curriculum mapping (Alberta PoS coverage).
8. **Enrolment page**: View metric cards, filter by grade, export CSV.

### Admin Flow (permission differences)

1. Log in as **School Admin**
2. Visit Experiences -- no "Create Experience" button
3. Visit Experience Detail -- no "Edit" or "Create Cohort" buttons
4. Visit Cohort Detail -- no "Edit", "Activate", or "Complete" buttons, but "Enrol Student" IS still visible
5. Admins are read-only for content management but can manage student enrolments

### Student and Parent Flows

- **Student**: Sees a personal dashboard with own enrolments, progress, and credentials. Read-only.
- **Parent**: Sees linked children's data. Click a child to drill down. Cannot see other students' data (backend-enforced).

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

The demo ships with pre-seeded data for a realistic school scenario. Seeders run automatically during `docker compose up`.

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

## Frontend Structure

The frontend is a React 19 SPA built with TypeScript and Vite:

- **Tailwind CSS v4** with Hatchloom design tokens (Outfit + DM Sans fonts)
- **TanStack React Query** for server state management
- **React Router v7** for client-side routing
- **Vitest + React Testing Library + MSW** for testing

```
frontend/src/
  api/              API client and endpoint modules
    client.ts       Axios instance with auth interceptor
    dashboard.ts    Dashboard endpoints
    experiences.ts  Experience CRUD + sub-resources
    enrolments.ts   Cohort + enrolment endpoints
    courses.ts      Course catalogue endpoint
  components/
    layout/
      AdminLayout.tsx   Top nav + sidebar + outlet
    ui/                 Button, Card, Badge, MetricCard, Modal,
                        Input, Spinner, EmptyState, Pagination, Table
  context/
    AuthContext.tsx  Token-based auth with role flags
  pages/
    LoginPage.tsx           Quick-login for demo tokens
    admin/
      DashboardPage.tsx     Screen 300
      ExperiencesPage.tsx   Screen 301
      ExperienceDetailPage.tsx  Screen 302
      EnrolmentsPage.tsx    Screen 303
      PlaceholderPage.tsx   Stub for non-Delta screens
  types/
    index.ts        Shared TypeScript interfaces
  __tests__/        Vitest setup, test utilities, MSW mocks
```

---

## Running Tests

### Frontend Tests

The frontend has 135 tests covering UI components, auth context, API modules, and all page screens:

```bash
cd frontend
npm install
npm test
```

To run in watch mode during development:

```bash
npm run test:watch
```

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
| `APP_NAME` | Laravel | All backends | Service name |
| `APP_KEY` | (generated) | All backends | Laravel encryption key |
| `APP_ENV` | local | All backends | Environment (local, testing, production) |
| `APP_DEBUG` | true | All backends | Enable debug mode |
| `DB_CONNECTION` | pgsql | All backends | Database driver |
| `DB_HOST` | postgres | All backends | Database host (Docker service name or IP) |
| `DB_PORT` | 5432 | All backends | Database port |
| `DB_DATABASE` | hatchloom | All backends | Database name |
| `DB_USERNAME` | hatchloom | All backends | Database user |
| `DB_PASSWORD` | secret | All backends | Database password |
| `AUTH_MODE` | http | All backends | Auth mode: `http` (real JWT via Quebec) or `mock` (static tokens) |
| `USER_SERVICE_URL` | http://localhost:8080 | All backends | URL for Team Quebec's User Service (JWT auth, when AUTH_MODE=http) |
| `COURSE_SERVICE_URL` | http://localhost:8004 | Experience, Dashboard | URL for Team Papa's Course Service (when AUTH_MODE=http) |
| `CREDENTIAL_SERVICE_URL` | http://localhost:8005 | Enrolment, Dashboard | URL for Karl's Credential Engine (when AUTH_MODE=http) |
| `EXPERIENCE_SERVICE_URL` | http://experience-service:8002 | Dashboard | URL for Experience Service (cross-service calls) |
| `ENROLMENT_SERVICE_URL` | http://enrolment-service:8003 | Dashboard, Experience | URL for Enrolment Service (cross-service calls) |
| `CACHE_STORE` | array | All backends | Cache driver |
| `SESSION_DRIVER` | array | All backends | Session driver |
| `QUEUE_CONNECTION` | sync | All backends | Queue driver (synchronous for D1) |

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
| Container shows `unhealthy` or exits | Upstream dependency not ready yet | Wait 60-90s for healthcheck chain to complete, then run `docker compose ps` |
| Code changes not reflected | Source is baked into images at build time | Run `docker compose build` then `docker compose up -d` |
| Port conflict on 3000/8001/8002/8003 | Another process using the port | Stop the conflicting process or edit port mappings in `docker-compose.yml` |
| Frontend shows blank page or API errors | Backend services not running | Start backends first: `docker compose up -d`, wait for healthy, then start frontend |
| `npm run dev` proxy errors | Backend ports unreachable | Verify backends are healthy: `docker compose ps` |

---

## API Contract

**Integrating with Delta?** See [`API-CONTRACT.docx`](API-CONTRACT.docx) for full endpoint documentation, request/response shapes, authentication tokens, data ownership, and integration contracts for each team.

---

## Known Limitations

- **School scoping uses mock data** -- only one school (Ridgewood Academy) is seeded. Multi-tenant isolation is implemented but not tested across multiple schools.
- **No API gateway** -- services call each other directly over the Docker network. In Docker, nginx in the frontend container handles API proxying. In local dev, Vite's proxy handles it.
- **Papa and Quebec services must be running** -- when `AUTH_MODE=http`, the Quebec User Service and Papa Course Service must be reachable at the configured URLs. Set `AUTH_MODE=mock` for standalone development.
- **Credential data from Karl** -- `HttpCredentialDataProvider` is implemented and ready, but Karl's Credential Engine endpoints are not yet deployed. When `AUTH_MODE=http` and Karl's service is unavailable, credential data gracefully degrades to empty arrays.

---

## Team Members

**CSSD 2203 (Software Design):** Bhagya, Nathan, Neharika, Shlok

**CSSD 2211 (Cloud Computing):** Bhagya, Miguel, Neharika
