<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\CohortEnrolment;
use App\Models\Experience;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for AuditLogMiddleware — verifies that mutating HTTP requests
 * (POST, PUT, PATCH, DELETE) produce structured audit log entries and
 * that GET requests do not. Also verifies sensitive field redaction.
 */
class AuditLogMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private School $school;
    private Experience $experience;
    private Cohort $cohort;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Ridgewood Academy',
            'code' => 'RIDGE',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'id' => 1,
            'name' => 'Admin User',
            'email' => 'admin@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_admin',
            'school_id' => $this->school->id,
        ]);

        $this->experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Test experience',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $this->cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort A',
            'status' => 'active',
            'capacity' => 25,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $this->student = User::create([
            'id' => 4,
            'name' => 'Student 1',
            'email' => 'student1@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-admin-token'];
    }

    // ── 1. POST request generates an audit log entry ──────────────

    public function test_post_request_generates_audit_log_entry(): void
    {
        Log::spy();

        $this->postJson("/api/school/cohorts/{$this->cohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'audit.mutation'
                    && $context['http_method'] === 'POST'
                    && $context['user_id'] === $this->admin->id
                    && $context['user_role'] === 'school_admin'
                    && str_contains($context['uri'], 'enrolments')
                    && isset($context['response_status'])
                    && isset($context['timestamp']);
            })
            ->once();
    }

    // ── 2. PUT/PATCH request generates an audit log entry ─────────

    public function test_put_request_generates_audit_log_entry(): void
    {
        $notStartedCohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Updatable Cohort',
            'status' => 'not_started',
            'capacity' => 20,
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        Log::spy();

        $this->putJson("/api/school/cohorts/{$notStartedCohort->id}", [
            'name' => 'Renamed Cohort',
            'capacity' => 35,
        ], $this->authHeaders());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'audit.mutation'
                    && $context['http_method'] === 'PUT';
            })
            ->once();
    }

    public function test_patch_request_generates_audit_log_entry(): void
    {
        $notStartedCohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Activatable Cohort',
            'status' => 'not_started',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        Log::spy();

        $this->patchJson("/api/school/cohorts/{$notStartedCohort->id}/activate", [], $this->authHeaders());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'audit.mutation'
                    && $context['http_method'] === 'PATCH';
            })
            ->once();
    }

    // ── 3. DELETE request generates an audit log entry ─────────────

    public function test_delete_request_generates_audit_log_entry(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->cohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        Log::spy();

        $this->deleteJson(
            "/api/school/cohorts/{$this->cohort->id}/enrolments/{$this->student->id}",
            [],
            $this->authHeaders()
        );

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'audit.mutation'
                    && $context['http_method'] === 'DELETE';
            })
            ->once();
    }

    // ── 4. GET request does NOT generate an audit log entry ───────

    public function test_get_request_does_not_generate_audit_log_entry(): void
    {
        Log::spy();

        $this->getJson('/api/school/cohorts', $this->authHeaders());

        Log::shouldNotHaveReceived('info', function (string $message): bool {
            return $message === 'audit.mutation';
        });
    }

    // ── 5. Sensitive fields are redacted ──────────────────────────

    public function test_sensitive_fields_are_redacted_in_audit_log(): void
    {
        Log::spy();

        // POST a request body containing sensitive fields alongside the
        // required student_id. The middleware should redact sensitive values
        // before writing the log entry.
        $this->postJson("/api/school/cohorts/{$this->cohort->id}/enrolments", [
            'student_id' => $this->student->id,
            'password' => 'super-secret-123',
            'token' => 'tok_abc',
            'secret' => 'my-secret',
            'api_key' => 'key-xyz',
        ], $this->authHeaders());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                if ($message !== 'audit.mutation') {
                    return false;
                }

                $body = $context['request_body'];

                // The student_id should be preserved (not sensitive)
                if ((int) $body['student_id'] !== $this->student->id) {
                    return false;
                }

                // All sensitive fields must be redacted
                $sensitiveFields = ['password', 'token', 'secret', 'api_key'];
                foreach ($sensitiveFields as $field) {
                    if (!isset($body[$field]) || $body[$field] !== '***REDACTED***') {
                        return false;
                    }
                }

                return true;
            })
            ->once();
    }
}
