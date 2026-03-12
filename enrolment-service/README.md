# Enrolment Service

**Port:** 8003 | **Tables owned:** `cohorts`, `cohort_enrolments`

## Purpose

The Enrolment Service manages the Cohort and Enrolment domain for Screen 303. A Cohort is a live running instance of an Experience with a start date, end date, capacity, and enrolled students.

- **Screen 303 (Enrolment):** Student enrolment and assignment tracking, cohort lifecycle management, statistics, warnings, and CSV export

## Endpoints

All authenticated endpoints require `Authorization: Bearer {token}`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/cohorts` | List cohorts (`?experience_id=`, `?status=`) |
| POST | `/api/school/cohorts` | Create a new cohort for an experience |
| GET | `/api/school/cohorts/{id}` | Get cohort detail |
| PUT | `/api/school/cohorts/{id}` | Update a cohort (name, dates, capacity) |
| PATCH | `/api/school/cohorts/{id}/activate` | Transition from `not_started` to `active` |
| PATCH | `/api/school/cohorts/{id}/complete` | Transition from `active` to `completed` |
| POST | `/api/school/cohorts/{id}/enrolments` | Enrol a student (`{ "student_id": 10 }`) |
| DELETE | `/api/school/cohorts/{id}/enrolments/{studentId}` | Remove a student (soft delete) |
| GET | `/api/school/enrolments` | School-wide enrolment overview (`?search=`, `?experience_id=`, `?cohort_id=`, `?grade=`) |
| GET | `/api/school/enrolments/students/{studentId}` | Student drill-down from enrolment context |
| GET | `/api/school/enrolments/statistics` | Enrolment statistics with warnings |
| GET | `/api/school/enrolments/export` | Export enrolments as CSV |
| GET | `/api/school/enrolments/health` | Health check (no auth required) |

## Design Patterns

### State Pattern -- Cohort Lifecycle

Cohort status follows a strict one-directional lifecycle. Invalid transitions are rejected with a 409 Conflict response.

```
not_started  -->  active  -->  completed
```

Implemented with:
- **`CohortState`** interface defining allowed transitions
- **`NotStartedState`** -- allows activation only
- **`ActiveState`** -- allows completion only
- **`CompletedState`** -- terminal state, no further transitions

### Observer Pattern -- Enrolment Events

Laravel events are dispatched when students are enrolled or removed from cohorts. These events can trigger notifications, audit logging, or statistics recalculation (planned for full implementation in D2).

### Soft Deletes for Enrolments

Students are never hard-deleted from cohorts. Removal sets `status = 'removed'` and records a `removed_at` timestamp, preserving the full audit trail.

## Enrolment Business Rules

- Cohort must be `active` to accept enrolments
- Cohort must not be at capacity
- Student must not already be enrolled in the same cohort
- Student must belong to the same school as the cohort

## Migrations and Seeding

```bash
php artisan migrate --seed
```

Seeds: schools, users, experiences (reference data), 3 cohorts (Cohort A active, Cohort B not_started, Cohort C active), and 8 student enrolments.

## Running Tests

```bash
# Locally (requires PostgreSQL, or configure .env.testing)
cd enrolment-service
composer install
cp .env.testing .env
php artisan key:generate
php artisan migrate
vendor/bin/phpunit

# Via Docker
docker compose exec enrolment-service php artisan test
```
