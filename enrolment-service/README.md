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
| GET | `/api/school/cohorts` | List cohorts (supports `?experience_id=`, `?status=`) |
| POST | `/api/school/cohorts` | Create new cohort |
| GET | `/api/school/cohorts/{id}` | Get cohort detail |
| PUT | `/api/school/cohorts/{id}` | Update cohort |
| PATCH | `/api/school/cohorts/{id}/activate` | Transition cohort to active |
| PATCH | `/api/school/cohorts/{id}/complete` | Transition cohort to completed |
| POST | `/api/school/cohorts/{id}/enrolments` | Enrol a student |
| DELETE | `/api/school/cohorts/{id}/enrolments/{studentId}` | Remove a student (soft delete) |
| GET | `/api/school/enrolments` | Enrolment overview (supports `?search=`, `?experience_id=`, `?cohort_id=`, `?grade=`) |
| GET | `/api/school/enrolments/students/{studentId}` | Student drill-down from enrolment context |
| GET | `/api/school/enrolments/statistics` | Enrolment statistics with warnings |
| GET | `/api/school/enrolments/export` | Export enrolments as CSV |
| GET | `/api/school/enrolments/health` | Health check |

## Running Tests

```bash
php artisan test
```
