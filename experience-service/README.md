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
| GET | `/api/experiences` | List experiences |
| POST | `/api/experiences` | Create experience |
| GET | `/api/experiences/{id}` | Get experience detail |
| PUT | `/api/experiences/{id}` | Update experience |
| DELETE | `/api/experiences/{id}` | Archive experience |
| GET | `/api/experiences/{id}/students` | Enrolled students |
| GET | `/api/experiences/{id}/contents` | Course contents |
| GET | `/api/experiences/{id}/statistics` | Statistics |
| GET | `/api/experiences/health` | Health check |

## Running Tests

```bash
php artisan test
```
