# Known Issues

Honest accounting of what is incomplete, stubbed, or limited in the current codebase. Written for the Riipen leads who will build on this after handoff.

## External Service Dependencies

Delta's three services talk to each other over HTTP and that works end-to-end. However, four strategy-pattern interfaces depend on **external services from other teams** that may not yet be deployed:

| Interface | Service | External Dependency | Env Var |
|---|---|---|---|
| `CourseDataProviderInterface` | Experience | Team Papa's Course Service | `COURSE_SERVICE_URL` |
| `StudentProgressProviderInterface` | Dashboard | Team Papa's Course Service | `COURSE_SERVICE_URL` |
| `CredentialDataProviderInterface` | Dashboard + Enrolment | Karl's Credential Engine | `CREDENTIAL_SERVICE_URL` |
| `LaunchPadDataProviderInterface` | Dashboard | Team Quebec's User Service | `USER_SERVICE_URL` |

Each interface has both an `Http*Provider` (production) and a `Mock*Provider` (dev). The toggle is `AUTH_MODE`: `http` uses the real providers, `mock` uses the mocks.

**Current state:** The HTTP providers are fully implemented and degrade gracefully (return zeros or empty arrays on failure). However, `COURSE_SERVICE_URL`, `CREDENTIAL_SERVICE_URL`, and `USER_SERVICE_URL` are **not set** in `docker-compose.yml` because those services don't run inside Delta's Docker stack. They default to `localhost:8004`, `localhost:8005`, and `localhost:8080` respectively. You will need to set these env vars when the external services are available.

## Hardcoded / Stubbed Data

These fields return placeholder values because they require data from external services that Delta does not own:

### Dashboard Service — Student Drill-Down Progress

`dashboard-service/app/Services/DashboardService.php` line 286:

```php
'progress' => [
    'courses_completed' => 1,
    'courses_in_progress' => 2,
    'overall_completion' => 0.35,
],
```

These values are **hardcoded inline**, not behind the strategy pattern. Replacing them requires calling Team Papa's Course Service for per-student progress data. The ideal endpoint shape is documented in `HttpStudentProgressProvider.php`.

### Experience Service — Student Detail Credits

`experience-service/app/Services/ExperienceScreenService.php` line 172:

```php
'credits' => [
    'earned' => 0,
    'total' => 0,
    'progress' => 0.0,
],
```

Stubbed at zero. Needs Karl's Credential Engine and Papa's Course Service.

### Experience Service — Experience Statistics

`experience-service/app/Services/ExperienceScreenService.php` line 260:

```php
'completion' => [
    'completed' => 0,           // Stub — needs Course Service progress data
    'in_progress' => $activeStudents,
    'not_started' => 0,         // Stub — needs Course Service progress data
    'completion_rate' => $completionRate,
],
'credit_progress' => [
    'average' => 0.0,           // Stub — needs credential engine integration
    'students_with_credits' => 0,
],
```

The `completed`, `not_started`, `average`, and `students_with_credits` fields are stubbed at zero. The `completion_rate` field uses a proxy calculation (active / total students) rather than real course-completion tracking.

## Quebec API Limitation

`HttpLaunchPadDataProvider` connects to Quebec's User Service successfully, but Quebec's `/profile` endpoint only exposes an `activeVentures` count per student — not individual venture details (names, statuses, created dates). As a result:

- `countActiveVentures()` works correctly (sums the count across students)
- `getStudentVentures()` returns the active count but the `ventures` array is always empty and `completed` is always 0

This is a limitation of Quebec's current API, not a Delta bug. If Quebec adds a venture detail endpoint, `HttpLaunchPadDataProvider.getStudentVentures()` should be updated to call it.
