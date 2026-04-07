# Enrolment Service — API Documentation

**Service:** Enrolment Service  
**Port:** `8003`  
**Base URL:** `http://localhost:8003/api`  
**Tables owned:** `cohorts`, `cohort_enrolments`  
**Screen:** 303 (Enrolment)

---

## Authentication

All endpoints except `/school/enrolments/health` require a JWT bearer token.

```
Authorization: Bearer <token>
```

**Development (AUTH_MODE=mock):**

| Token | Role |
|---|---|
| `test-admin-token` | `school_admin` |
| `test-teacher-token` | `school_teacher` |
| `test-student-token` | `student` |
| `test-parent-token` | `parent` |

**Missing or invalid token → `401 UNAUTHENTICATED`**

---

## Roles & Permissions

| Endpoint | school_admin | school_teacher | student | parent |
|---|---|---|---|---|
| GET cohorts | ✅ | ✅ | ✅ (own school) | ✅ (own school) |
| POST cohorts | ✅ | ✅ | ❌ | ❌ |
| GET cohorts/{id} | ✅ | ✅ | ✅ | ✅ |
| PUT cohorts/{id} | ✅ | ✅ | ❌ | ❌ |
| PATCH activate | ✅ | ✅ | ❌ | ❌ |
| PATCH complete | ✅ | ✅ | ❌ | ❌ |
| POST enrolments | ✅ | ❌ | ❌ | ❌ |
| DELETE enrolments | ✅ | ❌ | ❌ | ❌ |
| GET enrolments | ✅ | ✅ | ✅ (own only) | ✅ (children only) |
| GET enrolments/statistics | ✅ | ✅ | ❌ | ❌ |
| GET enrolments/export | ✅ | ✅ | ❌ | ❌ |
| GET enrolments/students/{id} | ✅ | ✅ | ✅ (own only) | ✅ (children only) |
| GET enrolments/health | ✅ (public) | ✅ (public) | ✅ (public) | ✅ (public) |

---

## Error Response Format

All error responses use a consistent JSON envelope:

```json
{
  "error": true,
  "message": "Human-readable description",
  "code": "MACHINE_READABLE_CODE"
}
```

**Standard error codes:**

| HTTP Status | Code | When |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Missing or invalid bearer token |
| 403 | `FORBIDDEN` | Valid token, insufficient role |
| 404 | `NOT_FOUND` | Resource does not exist |
| 409 | `INVALID_STATE_TRANSITION` | Cohort state transition not allowed |
| 422 | `VALIDATION_ERROR` | Missing or invalid request fields |
| 422 | `DUPLICATE_ENROLMENT` | Student already enrolled in cohort |

---

## Cohort Lifecycle

Cohorts follow a one-directional state machine. Invalid transitions return `409`.

```
not_started  ──PATCH /activate──▶  active  ──PATCH /complete──▶  completed
```

- New cohorts always start as `not_started`
- Only `not_started` → `active` via `/activate`
- Only `active` → `completed` via `/complete`
- Transitions are irreversible
- Students can only be enrolled in `active` cohorts

---

## Endpoints

---

### 1. Health Check

```
GET /api/school/enrolments/health
```

**Auth:** None (public — for Docker health probes)

**Response `200 OK`:**
```json
{
  "status": "ok",
  "service": "enrolment",
  "timestamp": "2026-04-01T14:30:00+00:00",
  "database": "connected"
}
```

**Response `503` (database unreachable):**
```json
{
  "status": "error",
  "service": "enrolment",
  "timestamp": "2026-04-01T14:30:00+00:00",
  "database": "unreachable"
}
```

---

### 2. List Cohorts

```
GET /api/school/cohorts
```

**Auth:** All roles  
**Scoped to:** Authenticated user's school

**Query Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `experience_id` | integer | Filter to cohorts of a specific experience |
| `status` | string | Filter by lifecycle state: `not_started`, `active`, `completed` |
| `search` | string | Case-insensitive substring match on cohort name |

**Example Request:**
```bash
curl -4 -H "Authorization: Bearer test-admin-token" \
  "http://localhost:8003/api/school/cohorts?status=active&experience_id=1"
```

**Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Cohort A",
      "experience_id": 1,
      "status": "active",
      "teacher_name": "Ms. Smith",
      "student_count": 6,
      "removed_count": 1,
      "capacity": 25,
      "start_date": "2026-02-01",
      "end_date": "2026-06-01"
    }
  ]
}
```

---

### 3. Create Cohort

```
POST /api/school/cohorts
```

**Auth:** `school_admin`, `school_teacher`

**Request Body:**

| Field | Type | Required | Validation |
|---|---|---|---|
| `experience_id` | integer | ✅ | Must reference an existing experience in the admin's school |
| `name` | string | ✅ | Max 255 chars, non-empty, not whitespace-only |
| `start_date` | string (Y-m-d) | ✅ | Today or later |
| `end_date` | string (Y-m-d) | ✅ | Must be after `start_date` |
| `capacity` | integer | ❌ | 1–10000 if provided; null = unlimited |
| `teacher_id` | integer | ❌ | Must reference an existing user in the admin's school |

New cohorts are always created with `status = not_started`.

**Example Request:**
```bash
curl -4 -X POST \
  -H "Authorization: Bearer test-admin-token" \
  -H "Content-Type: application/json" \
  -d '{
    "experience_id": 1,
    "name": "Cohort A",
    "start_date": "2026-05-01",
    "end_date": "2026-08-01",
    "capacity": 30,
    "teacher_id": 2
  }' \
  http://localhost:8003/api/school/cohorts
```

**Response `201 Created`:**
```json
{
  "id": 1,
  "name": "Cohort A",
  "experience_id": 1,
  "status": "not_started",
  "capacity": 30,
  "start_date": "2026-05-01",
  "end_date": "2026-08-01",
  "created_at": "2026-04-01T14:30:00+00:00"
}
```

**Error `422`** — Validation failed:
```json
{
  "error": true,
  "message": "The start date field must be a date after or equal to today.",
  "code": "VALIDATION_ERROR"
}
```

---

### 4. Get Cohort Detail

```
GET /api/school/cohorts/{id}
```

**Auth:** All roles  
**Path Parameter:** `id` — integer, cohort ID

**Example Request:**
```bash
curl -4 -H "Authorization: Bearer test-admin-token" \
  http://localhost:8003/api/school/cohorts/1
```

**Response `200 OK`:**
```json
{
  "id": 1,
  "name": "Cohort A",
  "experience_id": 1,
  "status": "active",
  "teacher_name": "Ms. Smith",
  "student_count": 6,
  "removed_count": 1,
  "capacity": 25,
  "start_date": "2026-02-01",
  "end_date": "2026-06-01"
}
```

**Error `404`:**
```json
{
  "error": true,
  "message": "Cohort not found",
  "code": "NOT_FOUND"
}
```

---

### 5. Update Cohort

```
PUT /api/school/cohorts/{id}
```

**Auth:** `school_admin`, `school_teacher`  
**Path Parameter:** `id` — integer, cohort ID

All fields are optional — only provided fields are updated. `experience_id` and `status` cannot be changed via this endpoint (use `/activate` and `/complete` for status changes).

**Request Body:**

| Field | Type | Validation |
|---|---|---|
| `name` | string | Max 255 chars, non-empty, not whitespace-only |
| `start_date` | string (Y-m-d) | Valid date |
| `end_date` | string (Y-m-d) | Must be after `start_date` |
| `capacity` | integer | 1–10000 |
| `teacher_id` | integer | Must reference a user in the admin's school |

**Example Request:**
```bash
curl -4 -X PUT \
  -H "Authorization: Bearer test-admin-token" \
  -H "Content-Type: application/json" \
  -d '{"name": "Cohort A — Renamed", "capacity": 35}' \
  http://localhost:8003/api/school/cohorts/1
```

**Response `200 OK`:**
```json
{
  "id": 1,
  "name": "Cohort A — Renamed",
  "experience_id": 1,
  "status": "not_started",
  "capacity": 35,
  "start_date": "2026-05-01",
  "end_date": "2026-08-01"
}
```

---

### 6. Activate Cohort

```
PATCH /api/school/cohorts/{id}/activate
```

**Auth:** `school_admin`, `school_teacher`  
**Path Parameter:** `id` — integer, cohort ID  
**Body:** None

Transitions a cohort from `not_started` → `active`. Once active, students can be enrolled. Only `not_started` cohorts can be activated.

**Example Request:**
```bash
curl -4 -X PATCH \
  -H "Authorization: Bearer test-admin-token" \
  http://localhost:8003/api/school/cohorts/1/activate
```

**Response `200 OK`:**
```json
{
  "id": 1,
  "name": "Cohort A",
  "status": "active"
}
```

**Error `409`** — Cohort is already active or completed:
```json
{
  "error": true,
  "message": "Cohort is already active or completed",
  "code": "INVALID_STATE_TRANSITION"
}
```

---

### 7. Complete Cohort

```
PATCH /api/school/cohorts/{id}/complete
```

**Auth:** `school_admin`, `school_teacher`  
**Path Parameter:** `id` — integer, cohort ID  
**Body:** None

Transitions a cohort from `active` → `completed`. This is a terminal state — completed cohorts cannot be reactivated. Only `active` cohorts can be completed.

**Example Request:**
```bash
curl -4 -X PATCH \
  -H "Authorization: Bearer test-admin-token" \
  http://localhost:8003/api/school/cohorts/1/complete
```

**Response `200 OK`:**
```json
{
  "id": 1,
  "name": "Cohort A",
  "status": "completed"
}
```

**Error `409`** — Cohort is not active:
```json
{
  "error": true,
  "message": "Cohort must be active to complete",
  "code": "INVALID_STATE_TRANSITION"
}
```

---

### 8. Enrol Student

```
POST /api/school/cohorts/{cohortId}/enrolments
```

**Auth:** `school_admin` only  
**Path Parameter:** `cohortId` — integer, cohort ID

**Validation chain (in order):**
1. Cohort exists (`404` if not)
2. Student exists, belongs to the same school, has `role=student` (`422` if not)
3. Cohort status is `active` (`422` if not)
4. Cohort is not at capacity (`422` if full)
5. No existing active enrolment for this student-cohort pair (`422 DUPLICATE_ENROLMENT` if exists)

**Request Body:**

| Field | Type | Required | Validation |
|---|---|---|---|
| `student_id` | integer | ✅ | Must exist, same school, role=student |

**Example Request:**
```bash
curl -4 -X POST \
  -H "Authorization: Bearer test-admin-token" \
  -H "Content-Type: application/json" \
  -d '{"student_id": 10}' \
  http://localhost:8003/api/school/cohorts/1/enrolments
```

**Response `201 Created`:**
```json
{
  "id": 5,
  "cohort_id": 1,
  "student_id": 10,
  "status": "enrolled",
  "enrolled_at": "2026-04-01T14:30:00+00:00"
}
```

**Error `422` — Cohort not active:**
```json
{
  "error": true,
  "message": "Cohort is not active",
  "code": "VALIDATION_ERROR"
}
```

**Error `422` — Duplicate enrolment:**
```json
{
  "error": true,
  "message": "Student is already enrolled in this cohort",
  "code": "DUPLICATE_ENROLMENT"
}
```

**Error `422` — Cohort at capacity:**
```json
{
  "error": true,
  "message": "Cohort is at full capacity",
  "code": "VALIDATION_ERROR"
}
```

---

### 9. Remove Student

```
DELETE /api/school/cohorts/{cohortId}/enrolments/{studentId}
```

**Auth:** `school_admin` only  
**Path Parameters:**
- `cohortId` — integer, cohort ID
- `studentId` — integer, student user ID

Soft-deletes the enrolment: sets `status = removed` and records `removed_at`. The record is preserved in the database for audit trail and CSV export. The student can be re-enrolled later.

**Example Request:**
```bash
curl -4 -X DELETE \
  -H "Authorization: Bearer test-admin-token" \
  http://localhost:8003/api/school/cohorts/1/enrolments/10
```

**Response `200 OK`:**
```json
{
  "message": "Student removed from cohort"
}
```

**Error `404`** — Enrolment not found:
```json
{
  "error": true,
  "message": "Enrolment not found",
  "code": "NOT_FOUND"
}
```

---

### 10. Enrolment Overview

```
GET /api/school/enrolments
```

**Auth:** All roles  
**Role scoping:** Students see only their own row. Parents see only their linked children.

Returns a paginated list of students and their cohort assignments.

**Query Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `search` | string | Case-insensitive substring match on student name |
| `experience_id` | integer | Filter to students enrolled in cohorts of this experience |
| `cohort_id` | integer | Filter to students enrolled in this specific cohort |
| `grade` | integer | Filter by student grade (column available when users table includes grade) |
| `per_page` | integer | Page size (default 15, min 1, max 100) |

**Assignment status values:**

| Status | Meaning |
|---|---|
| `assigned` | Student has at least one active enrolment in an active cohort |
| `removed` | All of the student's enrolments are removed |
| `not_assigned` | Student has no enrolments |

**Example Request:**
```bash
curl -4 -H "Authorization: Bearer test-admin-token" \
  "http://localhost:8003/api/school/enrolments?search=alex&per_page=10"
```

**Response `200 OK`:**
```json
{
  "data": [
    {
      "student_id": 10,
      "name": "Alex Johnson",
      "email": "alex@ridgewood.edu",
      "assignment_status": "assigned",
      "cohort_assignments": [
        {
          "cohort_id": 1,
          "cohort_name": "Cohort A",
          "experience_id": 1,
          "experience_name": "Business Foundations",
          "status": "enrolled",
          "enrolled_at": "2026-02-15T09:00:00+00:00"
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 4,
    "per_page": 10,
    "total": 32
  }
}
```

---

### 11. Enrolment Statistics

```
GET /api/school/enrolments/statistics
```

**Auth:** `school_admin`, `school_teacher`

Returns school-wide enrolment counts and actionable warnings for Screen 303's statistics panel.

**Warning types:**

| Type | Severity | Triggered when |
|---|---|---|
| `unassigned_students` | `warning` | Any students have no active cohort enrolment |
| `capacity_warning` | `info` | An active cohort reaches ≥ 90% of its capacity |

**Example Request:**
```bash
curl -4 -H "Authorization: Bearer test-admin-token" \
  http://localhost:8003/api/school/enrolments/statistics
```

**Response `200 OK`:**
```json
{
  "total_students": 32,
  "enrolled": 28,
  "assigned": 25,
  "not_assigned": 7,
  "removed": 3,
  "warnings": [
    {
      "type": "unassigned_students",
      "message": "7 students are not assigned to any active cohort",
      "severity": "warning"
    },
    {
      "type": "capacity_warning",
      "message": "Cohort A is at 92% capacity (23/25)",
      "severity": "info"
    }
  ]
}
```

**Fields:**

| Field | Type | Description |
|---|---|---|
| `total_students` | integer | All students in the school |
| `enrolled` | integer | Students with at least one active enrolment (any cohort status) |
| `assigned` | integer | Students with at least one active enrolment in an active cohort |
| `not_assigned` | integer | `total_students - assigned` |
| `removed` | integer | Total removed enrolment records (not unique students) |
| `warnings` | array | Actionable alerts for the admin |

---

### 12. Student Enrolment Drill-Down

```
GET /api/school/enrolments/students/{studentId}
```

**Auth:** All roles  
**Role scoping:** Students can only view their own detail. Parents can only view their linked children.  
**Path Parameter:** `studentId` — integer, student user ID

Returns a single student's complete enrolment history plus a credential summary.

**Example Request:**
```bash
curl -4 -H "Authorization: Bearer test-admin-token" \
  http://localhost:8003/api/school/enrolments/students/10
```

**Response `200 OK`:**
```json
{
  "student": {
    "id": 10,
    "name": "Alex Johnson",
    "email": "alex@ridgewood.edu",
    "grade": 10
  },
  "enrolments": [
    {
      "cohort_id": 1,
      "cohort_name": "Cohort A",
      "experience_id": 1,
      "experience_name": "Business Foundations",
      "status": "enrolled",
      "enrolled_at": "2026-02-15T09:00:00+00:00"
    },
    {
      "cohort_id": 2,
      "cohort_name": "Cohort B",
      "experience_id": 1,
      "experience_name": "Business Foundations",
      "status": "removed",
      "enrolled_at": "2026-01-10T09:00:00+00:00"
    }
  ],
  "credentials": {
    "total_earned": 2,
    "in_progress": 1,
    "details": [
      {
        "id": 1,
        "name": "Entrepreneurial Thinking Foundations",
        "type": "credential",
        "status": "earned",
        "earned_at": "2026-02-15"
      },
      {
        "id": 2,
        "name": "Financial Literacy Completion",
        "type": "certificate",
        "status": "earned",
        "earned_at": "2026-03-05"
      },
      {
        "id": 3,
        "name": "Marketing Basics Badge",
        "type": "badge",
        "status": "in_progress",
        "earned_at": null
      }
    ]
  }
}
```

> **Note:** `credentials` is sourced from Karl's Credential Engine (`CREDENTIAL_SERVICE_URL`). When `AUTH_MODE=mock`, placeholder data is returned. When `AUTH_MODE=http`, the service calls the real credential endpoint.

**Error `404`:**
```json
{
  "error": true,
  "message": "Student not found",
  "code": "NOT_FOUND"
}
```

---

### 13. Export Enrolments (CSV)

```
GET /api/school/enrolments/export
```

**Auth:** `school_admin`, `school_teacher`

Streams a CSV file of all enrolment records for the school. Includes both active and removed enrolments for a complete audit trail.

**Query Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `cohort_id` | integer | Export only enrolments from this cohort |
| `experience_id` | integer | Export only enrolments from cohorts in this experience |

**CSV Columns:**
`student_name`, `student_email`, `cohort_name`, `experience_name`, `status`, `enrolled_at`, `removed_at`

All timestamps are ISO 8601 format. `removed_at` is empty for active enrolments.

**Example Request:**
```bash
curl -4 -H "Authorization: Bearer test-admin-token" \
  "http://localhost:8003/api/school/enrolments/export?experience_id=1" \
  -o enrolments.csv
```

**Response `200 OK`:**
```
Content-Type: text/csv
Content-Disposition: attachment; filename=enrolments.csv

student_name,student_email,cohort_name,experience_name,status,enrolled_at,removed_at
Alex Johnson,alex@ridgewood.edu,Cohort A,Business Foundations,enrolled,2026-02-15T09:00:00+00:00,
Daniel Park,daniel@ridgewood.edu,Cohort A,Business Foundations,removed,2026-01-10T09:00:00+00:00,2026-02-01T10:00:00+00:00
```

---

## External Service Dependencies

| Service | Variable | Used for |
|---|---|---|
| Quebec User Service | `USER_SERVICE_URL` | JWT token validation on every authenticated request |
| Karl's Credential Engine | `CREDENTIAL_SERVICE_URL` | Credential summary in student drill-down (`/enrolments/students/{id}`) |

If an external service is unreachable, affected endpoints return zero values or empty arrays rather than `500` errors.

---

## Running in Development (AUTH_MODE=mock)

```bash
# Start all services
docker compose up --build -d

# Test health
curl -4 http://localhost:8003/api/school/enrolments/health

# Authenticated request
curl -4 -H "Authorization: Bearer test-admin-token" \
  http://localhost:8003/api/school/enrolments/statistics
```

See the root `README.md` for full setup instructions including database seeding and cross-service integration.
