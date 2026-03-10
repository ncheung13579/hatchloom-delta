# Enrolment Service

**Port:** 8003
**Tables owned:** `cohorts`, `cohort_enrolments`

## Overview

The Enrolment Service manages Cohorts (live instances of Experiences) and student enrolments. Supports Screen 303 (Enrolment).

## Migrations & Seeding

```bash
php artisan migrate --seed
```

Seeds: schools, users, experiences (reference), 3 cohorts, 8 enrolments.

## Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/cohorts` | List cohorts |
| POST | `/api/cohorts` | Create cohort |
| GET | `/api/cohorts/{id}` | Get cohort detail |
| PUT | `/api/cohorts/{id}` | Update cohort |
| PATCH | `/api/cohorts/{id}/activate` | Activate cohort |
| PATCH | `/api/cohorts/{id}/complete` | Complete cohort |
| POST | `/api/cohorts/{id}/enrolments` | Enrol student |
| DELETE | `/api/cohorts/{id}/enrolments/{studentId}` | Remove student |
| GET | `/api/enrolments` | Enrolment overview |
| GET | `/api/enrolments/statistics` | Statistics with warnings |
| GET | `/api/enrolments/export` | Export CSV |
| GET | `/api/enrolments/health` | Health check |

## Running Tests

```bash
php artisan test
```
