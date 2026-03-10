# Experience Service

**Port:** 8002
**Tables owned:** `experiences`, `experience_courses`

## Overview

The Experience Service manages Experiences — collections of Hatchloom courses built by school teachers. Supports Screens 301 (Experiences Dashboard) and 302 (Experience Screen).

## Migrations & Seeding

```bash
php artisan migrate --seed
```

Seeds: schools, users, 2 experiences, 5 experience-course mappings.

## Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/school/experiences` | List experiences (supports `?search=`, `?page=`, `?per_page=`) |
| POST | `/api/school/experiences` | Create new experience |
| GET | `/api/school/experiences/{id}` | Get experience detail |
| PUT | `/api/school/experiences/{id}` | Update experience |
| DELETE | `/api/school/experiences/{id}` | Archive (soft-delete) experience |
| GET | `/api/school/experiences/{id}/students` | Enrolled students (supports `?search=`) |
| GET | `/api/school/experiences/{id}/students/{studentId}` | Student drill-down within experience |
| GET | `/api/school/experiences/{id}/students/export` | Export experience students as CSV |
| GET | `/api/school/experiences/{id}/contents` | Course contents and delivery |
| GET | `/api/school/experiences/{id}/statistics` | Experience statistics |
| GET | `/api/school/experiences/health` | Health check |

## Running Tests

```bash
php artisan test
```
