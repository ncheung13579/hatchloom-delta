# Experience Service

**Port:** 8002 | **Tables owned:** `experiences`, `experience_courses`

## Purpose

The Experience Service manages the Experience domain for Screens 301 and 302. An Experience is a collection of Hatchloom courses assembled by a teacher as a curriculum package.

- **Screen 301 (Experiences Dashboard):** List, search, and create Experiences
- **Screen 302 (Experience Screen):** View enrolled students, course contents and delivery schedule, and per-experience statistics

## Endpoints

All authenticated endpoints require `Authorization: Bearer {token}`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/experiences` | List experiences (`?search=`, `?page=`, `?per_page=`) |
| POST | `/api/school/experiences` | Create a new experience (`name`, `description`, `course_ids` required) |
| GET | `/api/school/experiences/{id}` | Get experience with courses and cohorts |
| PUT | `/api/school/experiences/{id}` | Update an experience |
| DELETE | `/api/school/experiences/{id}` | Archive an experience (soft-delete, sets status to `archived`) |
| GET | `/api/school/experiences/{id}/students` | Enrolled students across all cohorts (`?search=`) |
| GET | `/api/school/experiences/{id}/students/{studentId}` | Student drill-down within an experience |
| GET | `/api/school/experiences/{id}/students/export` | Export experience students as CSV |
| GET | `/api/school/experiences/{id}/contents` | Course contents and delivery schedule with block-level detail |
| GET | `/api/school/experiences/{id}/statistics` | Enrolment, completion, and credit progress statistics |
| GET | `/api/school/experiences/health` | Health check (no auth required) |

## Design Patterns

### Strategy Pattern -- Course Data Provider

Upstream course data (names, descriptions, block structure) comes from Team Papa's Course service, which is not available in D1. The Strategy pattern isolates this dependency:

- **`CourseDataProviderInterface`** defines the contract for fetching course catalogue data
- **`MockCourseDataProvider`** returns hardcoded course data for D1 development and testing
- When Team Papa's service is ready, a real `HttpCourseDataProvider` can be swapped in via Laravel's service container without changing any controller or service code

### Repository Pattern

`ExperienceService` and `ExperienceScreenService` act as the repository boundary between controllers and Eloquent models. Controllers validate input and return responses; all business logic lives in service classes.

## Migrations and Seeding

```bash
php artisan migrate --seed
```

Seeds: schools, users, 5 mock courses, 2 experiences (Business Foundations, Tech Explorers), and experience-course mappings.

## Running Tests

```bash
# Locally (requires PostgreSQL, or configure .env.testing)
cd experience-service
composer install
cp .env.testing .env
php artisan key:generate
php artisan migrate
vendor/bin/phpunit

# Via Docker
docker compose exec experience-service php artisan test
```
