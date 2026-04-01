<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\StudentEnrolled;
use App\Events\StudentRemoved;
use App\Models\Cohort;
use App\Models\CohortEnrolment;
use App\Models\Experience;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EnrolmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private School $school;
    private Experience $experience;
    private Cohort $activeCohort;
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
            'name' => 'Admin User',
            'email' => 'admin@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_admin',
            'school_id' => $this->school->id,
        ]); // auto-increment ID 1 → matches TOKEN_MAP 'test-admin-token'

        User::create([
            'name' => 'Ms. Smith',
            'email' => 'teacher1@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]); // auto-increment ID 2 → matches TOKEN_MAP 'test-teacher-token'

        User::create([
            'name' => 'Filler User',
            'email' => 'filler@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]); // auto-increment ID 3 → filler so student gets ID 4

        $this->student = User::create([
            'name' => 'Student 1',
            'email' => 'student1@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]); // auto-increment ID 4 → matches TOKEN_MAP 'test-student-token'

        $this->experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Test experience',
            'status' => 'active',
            'created_by' => 1,
        ]);

        $this->activeCohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort A',
            'status' => 'active',
            'capacity' => 25,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-admin-token'];
    }

    private function teacherAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer test-teacher-token'];
    }

    public function test_can_enrol_student(): void
    {
        $response = $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment([
                'cohort_id' => $this->activeCohort->id,
                'student_id' => $this->student->id,
                'status' => 'enrolled',
            ]);

        $this->assertDatabaseHas('cohort_enrolments', [
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
        ]);
    }

    public function test_cannot_enrol_duplicate_student(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_cannot_enrol_in_inactive_cohort(): void
    {
        $inactiveCohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort B',
            'status' => 'not_started',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        $response = $this->postJson("/api/school/cohorts/{$inactiveCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_can_remove_student(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->deleteJson(
            "/api/school/cohorts/{$this->activeCohort->id}/enrolments/{$this->student->id}",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Student removed from cohort']);

        $this->assertDatabaseHas('cohort_enrolments', [
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
        ]);
    }

    public function test_can_get_enrolment_overview(): void
    {
        $response = $this->getJson('/api/school/enrolments', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_enrolment_statistics_include_warnings(): void
    {
        // Student exists but is not assigned to any active cohort
        $response = $this->getJson('/api/school/enrolments/statistics', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_students',
                'enrolled',
                'assigned',
                'not_assigned',
                'warnings',
            ]);

        // Should have unassigned warning since student is not in any cohort
        $data = $response->json();
        $this->assertTrue($data['not_assigned'] > 0);
    }

    public function test_can_export_enrolment_csv(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->get('/api/school/enrolments/export', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    /**
     * Verify that passing experience_id narrows the student list to only those
     * enrolled in cohorts belonging to that experience.
     */
    public function test_can_filter_enrolments_by_experience(): void
    {
        // Create a second experience with its own cohort
        $experience2 = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Digital Marketing',
            'description' => 'Second experience',
            'status' => 'active',
            'created_by' => 1,
        ]);

        $cohort2 = Cohort::create([
            'experience_id' => $experience2->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort DM',
            'status' => 'active',
            'capacity' => 20,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        // Enrol student1 in experience1's cohort, student2 in experience2's cohort
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        CohortEnrolment::create([
            'cohort_id' => $cohort2->id,
            'student_id' => $student2->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        // Filter by experience1 — should only return student1
        $response = $this->getJson(
            "/api/school/enrolments?experience_id={$this->experience->id}",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->student->id, $data[0]['student_id']);
    }

    /**
     * Verify that passing cohort_id narrows the student list to only those
     * enrolled in that specific cohort.
     */
    public function test_can_filter_enrolments_by_cohort(): void
    {
        $cohort2 = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort B',
            'status' => 'active',
            'capacity' => 20,
            'start_date' => '2026-03-01',
            'end_date' => '2026-07-01',
        ]);

        $student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        CohortEnrolment::create([
            'cohort_id' => $cohort2->id,
            'student_id' => $student2->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        // Filter by cohort2 — should only return student2
        $response = $this->getJson(
            "/api/school/enrolments?cohort_id={$cohort2->id}",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($student2->id, $data[0]['student_id']);
    }

    /**
     * Verify the student detail drill-down returns the expected structure
     * including student info, enrolments list, and mock credential summary.
     */
    public function test_can_get_student_detail_from_enrolment(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->getJson(
            "/api/school/enrolments/students/{$this->student->id}",
            $this->authHeaders()
        );

        $response->assertStatus(200)
            ->assertJsonStructure([
                'student' => ['id', 'name', 'email', 'grade'],
                'enrolments' => [
                    ['cohort_id', 'cohort_name', 'experience_name', 'status', 'enrolled_at'],
                ],
                'credentials' => ['total_earned', 'in_progress', 'details'],
            ]);

        $data = $response->json();
        $this->assertEquals($this->student->id, $data['student']['id']);
        $this->assertEquals($this->student->name, $data['student']['name']);
        $this->assertCount(1, $data['enrolments']);
        $this->assertEquals($this->activeCohort->id, $data['enrolments'][0]['cohort_id']);
    }

    /**
     * Verify that requesting a non-existent student returns 404 with the
     * standard error envelope.
     */
    public function test_student_detail_not_found_returns_404(): void
    {
        $response = $this->getJson(
            '/api/school/enrolments/students/9999',
            $this->authHeaders()
        );

        $response->assertStatus(404)
            ->assertJsonFragment([
                'error' => true,
                'message' => 'Student not found',
                'code' => 'NOT_FOUND',
            ]);
    }

    /**
     * Verify that enrolling a student when the cohort is at capacity returns 422.
     */
    public function test_cannot_enrol_when_cohort_at_capacity(): void
    {
        // Create a cohort with capacity of 1
        $smallCohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Tiny Cohort',
            'status' => 'active',
            'capacity' => 1,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        // Fill the single slot
        CohortEnrolment::create([
            'cohort_id' => $smallCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        // Try to enrol a second student
        $student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        $response = $this->postJson("/api/school/cohorts/{$smallCohort->id}/enrolments", [
            'student_id' => $student2->id,
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cohort is at full capacity']);
    }

    /**
     * Verify that re-enrolling a removed student succeeds.
     * A new enrolment record is created while the removed one is preserved for audit.
     */
    public function test_can_reenrol_after_removal(): void
    {
        // Create a removed enrolment
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
            'enrolled_at' => now(),
            'removed_at' => now(),
        ]);

        // Re-enrol the same student
        $response = $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        $response->assertStatus(201);

        // Verify both records exist (removed + new enrolled) for audit trail
        $this->assertDatabaseCount('cohort_enrolments', 2);
    }

    /**
     * Verify that a student from a different school cannot be enrolled.
     */
    public function test_cannot_enrol_student_from_different_school(): void
    {
        $otherSchool = School::create([
            'name' => 'Other Academy',
            'code' => 'OTHER',
            'is_active' => true,
        ]);

        $otherStudent = User::create([
            'name' => 'Foreign Student',
            'email' => 'foreign@other.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $otherSchool->id,
        ]);

        $response = $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $otherStudent->id,
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Student not found or not in your school']);
    }

    /**
     * Verify that removing a student who is not enrolled returns 404.
     */
    public function test_remove_unenrolled_student_returns_404(): void
    {
        $response = $this->deleteJson(
            "/api/school/cohorts/{$this->activeCohort->id}/enrolments/{$this->student->id}",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(404)
            ->assertJsonFragment(['message' => 'Enrolment not found']);
    }

    /**
     * Verify that enrolling in a completed cohort is blocked.
     */
    public function test_cannot_enrol_in_completed_cohort(): void
    {
        $completedCohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Done Cohort',
            'status' => 'completed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
        ]);

        $response = $this->postJson("/api/school/cohorts/{$completedCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cohort is not active']);
    }

    /**
     * Verify enrolment statistics warning is generated for unassigned students.
     */
    public function test_statistics_contain_unassigned_warning_details(): void
    {
        // Student exists but has no enrolments
        $response = $this->getJson('/api/school/enrolments/statistics', $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertGreaterThan(0, $data['not_assigned']);
        $this->assertNotEmpty($data['warnings']);
        $warningTypes = array_column($data['warnings'], 'type');
        $this->assertContains('unassigned_students', $warningTypes);
    }

    /**
     * Verify capacity warning appears in statistics when a cohort is near full.
     */
    public function test_statistics_contain_capacity_warning(): void
    {
        // Create a cohort with capacity 2 and fill it with 2 students (100% = ≥90%)
        $smallCohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Nearly Full Cohort',
            'status' => 'active',
            'capacity' => 2,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        CohortEnrolment::create([
            'cohort_id' => $smallCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        CohortEnrolment::create([
            'cohort_id' => $smallCohort->id,
            'student_id' => $student2->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->getJson('/api/school/enrolments/statistics', $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json();
        $warningTypes = array_column($data['warnings'], 'type');
        $this->assertContains('capacity_warning', $warningTypes);
    }

    // ── Edge cases ─────────────────────────────────────────────

    public function test_enrol_nonexistent_cohort_returns_404(): void
    {
        $response = $this->postJson('/api/school/cohorts/9999/enrolments', [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        $response->assertStatus(404)
            ->assertJsonFragment([
                'error' => true,
                'code' => 'NOT_FOUND',
            ]);
    }

    public function test_enrol_without_student_id_fails_validation(): void
    {
        $response = $this->postJson(
            "/api/school/cohorts/{$this->activeCohort->id}/enrolments",
            [],
            $this->authHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_cannot_enrol_non_student_user(): void
    {
        // Teacher user (id=2) was created in setUp
        $response = $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => 2,
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Student not found or not in your school']);
    }

    public function test_search_enrolments_is_case_insensitive(): void
    {
        // Student "Student 1" was created in setUp
        $response = $this->getJson('/api/school/enrolments?search=student', $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Student 1', $data[0]['name']);
    }

    // ── Pagination ─────────────────────────────────────────────

    public function test_pagination_respects_per_page_for_enrolments(): void
    {
        // Create 5 students total
        for ($i = 2; $i <= 5; $i++) {
            User::create([
                'name' => "Student {$i}",
                'email' => "student{$i}@ridgewood.edu",
                'password' => bcrypt('password'),
                'role' => 'student',
                'school_id' => $this->school->id,
            ]);
        }

        $response = $this->getJson('/api/school/enrolments?per_page=2', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5);
    }

    // ── Tighter assertions ─────────────────────────────────────

    public function test_enrol_response_includes_enrolled_at(): void
    {
        $response = $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertNotNull($data['enrolled_at']);
        $this->assertEquals('enrolled', $data['status']);
        $this->assertEquals($this->activeCohort->id, $data['cohort_id']);
        $this->assertEquals($this->student->id, $data['student_id']);
    }

    public function test_remove_sets_removed_at_timestamp(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $this->deleteJson(
            "/api/school/cohorts/{$this->activeCohort->id}/enrolments/{$this->student->id}",
            [],
            $this->authHeaders()
        );

        $enrolment = CohortEnrolment::where('cohort_id', $this->activeCohort->id)
            ->where('student_id', $this->student->id)
            ->first();

        $this->assertEquals('removed', $enrolment->status);
        $this->assertNotNull($enrolment->removed_at);
    }

    public function test_csv_export_contains_expected_columns(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->get('/api/school/enrolments/export', $this->authHeaders());

        $response->assertStatus(200);
        $content = $response->streamedContent();
        $lines = explode("\n", trim($content));

        // Verify header row contains expected columns
        $headers = str_getcsv($lines[0]);
        $this->assertContains('student_name', $headers);
        $this->assertContains('student_email', $headers);
        $this->assertContains('cohort_name', $headers);
        $this->assertContains('experience_name', $headers);
        $this->assertContains('status', $headers);
        $this->assertContains('enrolled_at', $headers);
        $this->assertContains('removed_at', $headers);

        // Verify at least one data row exists
        $this->assertGreaterThanOrEqual(2, count($lines));
    }

    // ── Authentication & Authorization ──────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/school/enrolments');

        $response->assertStatus(401)
            ->assertJsonFragment([
                'error' => true,
                'code' => 'UNAUTHENTICATED',
            ]);
    }

    public function test_invalid_token_returns_401(): void
    {
        $response = $this->getJson('/api/school/enrolments', [
            'Authorization' => 'Bearer completely-invalid-token',
        ]);

        $response->assertStatus(401)
            ->assertJsonFragment([
                'error' => true,
                'code' => 'UNAUTHENTICATED',
            ]);
    }

    private function createHatchloomUsers(): void
    {
        \Illuminate\Support\Facades\DB::table('users')->insert([
            ['id' => 15, 'name' => 'Hatchloom Course Builder', 'email' => 'teacher@hatchloom.com', 'password' => bcrypt('password'), 'role' => 'hatchloom_teacher', 'school_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 16, 'name' => 'Hatchloom Platform Admin', 'email' => 'admin@hatchloom.com', 'password' => bcrypt('password'), 'role' => 'hatchloom_admin', 'school_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_hatchloom_teacher_cannot_access_enrolments(): void
    {
        $this->createHatchloomUsers();

        $response = $this->getJson('/api/school/enrolments', [
            'Authorization' => 'Bearer test-hatchloom-teacher-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    public function test_hatchloom_admin_cannot_access_enrolments(): void
    {
        $this->createHatchloomUsers();

        $response = $this->getJson('/api/school/enrolments', [
            'Authorization' => 'Bearer test-hatchloom-admin-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    public function test_student_role_can_read_enrolments(): void
    {
        // Students can read enrolment data (read-only access)
        $response = $this->getJson('/api/school/enrolments', [
            'Authorization' => 'Bearer test-student-token',
        ]);

        $response->assertStatus(200);
    }

    public function test_student_role_cannot_enrol(): void
    {
        // Students cannot perform write operations
        $response = $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], [
            'Authorization' => 'Bearer test-student-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment([
                'error' => true,
                'code' => 'FORBIDDEN',
            ]);
    }

    // ── CSV Export Content Validation ─────────────────────

    public function test_csv_export_data_rows_match_enrolment(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->get('/api/school/enrolments/export', $this->authHeaders());

        $response->assertStatus(200);
        $content = $response->streamedContent();
        $lines = explode("\n", trim($content));

        // Verify data row contains the enrolled student's info
        $this->assertGreaterThanOrEqual(2, count($lines));
        $dataRow = str_getcsv($lines[1]);
        $headers = str_getcsv($lines[0]);
        $row = array_combine($headers, $dataRow);

        $this->assertEquals('Student 1', $row['student_name']);
        $this->assertEquals('student1@ridgewood.edu', $row['student_email']);
        $this->assertEquals('Cohort A', $row['cohort_name']);
        $this->assertEquals('Business Foundations', $row['experience_name']);
        $this->assertEquals('enrolled', $row['status']);
        $this->assertNotEmpty($row['enrolled_at']);
    }

    public function test_csv_export_includes_removed_students(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
            'enrolled_at' => now(),
            'removed_at' => now(),
        ]);

        $response = $this->get('/api/school/enrolments/export', $this->authHeaders());

        $response->assertStatus(200);
        $content = $response->streamedContent();
        $lines = explode("\n", trim($content));
        $headers = str_getcsv($lines[0]);
        $dataRow = str_getcsv($lines[1]);
        $row = array_combine($headers, $dataRow);

        $this->assertEquals('removed', $row['status']);
        $this->assertNotEmpty($row['removed_at']);
    }

    // ── Concurrency Safety ────────────────────────────────

    public function test_application_level_duplicate_check_prevents_double_enrolment(): void
    {
        // First enrolment via API succeeds
        $response = $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        $response->assertStatus(201);

        // Second enrolment via API is blocked (student is currently enrolled)
        $response = $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonFragment(['code' => 'DUPLICATE_ENROLMENT']);
    }

    // ── Assignment status ─────────────────────────────────

    public function test_enrolment_overview_shows_correct_assignment_status(): void
    {
        // Enrolled student → should be "assigned"
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->getJson('/api/school/enrolments', $this->authHeaders());
        $response->assertStatus(200);

        $data = $response->json('data');
        $enrolledStudent = collect($data)->firstWhere('student_id', $this->student->id);
        $this->assertEquals('assigned', $enrolledStudent['assignment_status']);
        $this->assertNotEmpty($enrolledStudent['cohort_assignments']);
    }

    // ── Error envelope consistency ─────────────────────────────

    public function test_all_enrolment_errors_use_standard_envelope(): void
    {
        // 404 — nonexistent cohort
        $response = $this->postJson('/api/school/cohorts/9999/enrolments', [
            'student_id' => $this->student->id,
        ], $this->authHeaders());
        $response->assertJsonStructure(['error', 'message', 'code']);
        $this->assertTrue($response->json('error'));

        // 422 — inactive cohort
        $inactiveCohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Inactive',
            'status' => 'not_started',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);
        $response = $this->postJson("/api/school/cohorts/{$inactiveCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());
        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'message', 'code']);
        $this->assertTrue($response->json('error'));
    }

    // ── Observer pattern / Event dispatch tests ───────────────

    /**
     * Verify that enrolling a student dispatches the StudentEnrolled event.
     */
    public function test_enrolling_student_dispatches_student_enrolled_event(): void
    {
        Event::fake([StudentEnrolled::class]);

        $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        Event::assertDispatched(StudentEnrolled::class);
    }

    /**
     * Verify that removing a student dispatches the StudentRemoved event.
     */
    public function test_removing_student_dispatches_student_removed_event(): void
    {
        // Enrol first (without faking events, so the enrolment actually persists)
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        Event::fake([StudentRemoved::class]);

        $this->deleteJson(
            "/api/school/cohorts/{$this->activeCohort->id}/enrolments/{$this->student->id}",
            [],
            $this->authHeaders()
        );

        Event::assertDispatched(StudentRemoved::class);
    }

    /**
     * Verify that the StudentEnrolled event carries the correct cohort and enrolment data.
     */
    public function test_student_enrolled_event_carries_correct_data(): void
    {
        Event::fake([StudentEnrolled::class]);

        $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->authHeaders());

        Event::assertDispatched(StudentEnrolled::class, function (StudentEnrolled $event) {
            return $event->enrolment->student_id === $this->student->id
                && $event->enrolment->cohort_id === $this->activeCohort->id
                && $event->enrolment->status === 'enrolled'
                && $event->cohort->id === $this->activeCohort->id
                && $event->cohort->school_id === $this->school->id;
        });
    }

    // ── Removed student visibility ─────────────────────────

    /**
     * Verify that removed students still appear in the enrolment list
     * with status 'removed' so admins can see the full audit trail.
     */
    public function test_removed_students_appear_in_enrolment_list(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
            'enrolled_at' => now()->subDays(5),
            'removed_at' => now(),
        ]);

        $response = $this->getJson('/api/school/enrolments', $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json('data');
        $studentEntry = collect($data)->firstWhere('student_id', $this->student->id);
        $this->assertNotNull($studentEntry);
        // The student should appear with cohort_assignments showing the removed status
        $this->assertNotEmpty($studentEntry['cohort_assignments']);
    }

    /**
     * Verify that students with no cohort enrolments show assignment_status='not_assigned'.
     */
    public function test_not_assigned_status_for_student_without_cohort(): void
    {
        // Student exists in setUp but has no enrolments
        $response = $this->getJson('/api/school/enrolments', $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json('data');
        $studentEntry = collect($data)->firstWhere('student_id', $this->student->id);
        $this->assertNotNull($studentEntry);
        $this->assertEquals('not_assigned', $studentEntry['assignment_status']);
        $this->assertEmpty($studentEntry['cohort_assignments']);
    }

    // ── Grade field assertion ────────────────────────────────

    /**
     * Verify that the student detail endpoint includes the grade field
     * with the correct value when set.
     */
    public function test_student_detail_includes_grade_field(): void
    {
        // Update the student to have a grade
        $this->student->update(['grade' => 10]);

        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->getJson(
            "/api/school/enrolments/students/{$this->student->id}",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('grade', $data['student']);
        $this->assertEquals(10, $data['student']['grade']);
    }

    /**
     * Verify that the student detail endpoint handles null grade gracefully.
     */
    public function test_student_detail_handles_null_grade(): void
    {
        $response = $this->getJson(
            "/api/school/enrolments/students/{$this->student->id}",
            $this->authHeaders()
        );

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('grade', $data['student']);
        $this->assertNull($data['student']['grade']);
    }

    // ── Pagination edge cases ────────────────────────────────

    /**
     * Verify that requesting a page beyond the last page returns empty data.
     */
    public function test_pagination_page_beyond_last_returns_empty_data(): void
    {
        $response = $this->getJson('/api/school/enrolments?page=999', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    /**
     * Verify that per_page=1 returns exactly one record per page.
     */
    public function test_pagination_per_page_one(): void
    {
        // Create a second student so there are 2 total
        User::create([
            'name' => 'Student Extra',
            'email' => 'extra@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        $response = $this->getJson('/api/school/enrolments?per_page=1', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);
    }

    // ── Observer pattern / Event dispatch tests ───────────────

    /**
     * Verify that the StudentRemoved event carries correct data including the removedAt timestamp.
     */
    // ── Fix verification: per_page clamping ─────────────────

    public function test_per_page_clamped_to_max_100(): void
    {
        $response = $this->getJson('/api/school/enrolments?per_page=500', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_per_page_clamped_to_min_1(): void
    {
        $response = $this->getJson('/api/school/enrolments?per_page=0', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_per_page_negative_clamped_to_1(): void
    {
        $response = $this->getJson('/api/school/enrolments?per_page=-5', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1);
    }

    // ── CSV data integrity: values exported verbatim ──────────

    public function test_csv_export_preserves_data_verbatim(): void
    {
        $student = User::create([
            'name' => '=CMD("calc")',
            'email' => '+danger@school.test',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->get('/api/school/enrolments/export', $this->authHeaders());

        $response->assertStatus(200);
        $content = $response->streamedContent();

        // Values must be exported exactly as stored — no mutation
        // fputcsv doubles internal quotes: "calc" → ""calc"" in CSV format
        $this->assertStringContainsString('=CMD(""calc"")', $content);
        $this->assertStringContainsString('+danger@school.test', $content);
        // Verify no apostrophe prefix was added
        $this->assertStringNotContainsString("'=CMD", $content);
        $this->assertStringNotContainsString("'+danger", $content);
    }

    // ── Fix verification: transaction wrapping (enrol) ────────

    public function test_enrol_uses_transaction_capacity_enforced(): void
    {
        // Create a cohort with capacity 1 and fill it
        $tinyCohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Tiny Cohort',
            'status' => 'active',
            'capacity' => 1,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        CohortEnrolment::create([
            'cohort_id' => $tinyCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        // This should fail — capacity is 1 and already full
        $response = $this->postJson("/api/school/cohorts/{$tinyCohort->id}/enrolments", [
            'student_id' => $student2->id,
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cohort is at full capacity']);

        // Verify only 1 enrolment exists
        $this->assertEquals(1, CohortEnrolment::where('cohort_id', $tinyCohort->id)->count());
    }

    public function test_student_removed_event_carries_correct_data_with_removed_at(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        Event::fake([StudentRemoved::class]);

        $this->deleteJson(
            "/api/school/cohorts/{$this->activeCohort->id}/enrolments/{$this->student->id}",
            [],
            $this->authHeaders()
        );

        Event::assertDispatched(StudentRemoved::class, function (StudentRemoved $event) {
            return $event->enrolment->student_id === $this->student->id
                && $event->enrolment->cohort_id === $this->activeCohort->id
                && $event->enrolment->status === 'removed'
                && $event->cohort->id === $this->activeCohort->id
                && $event->cohort->school_id === $this->school->id
                && $event->removedAt instanceof \DateTimeInterface
                && $event->removedAt == $event->enrolment->removed_at;
        });
    }

    /**
     * Teacher role restriction tests — screens 300-303 are admin-only.
     * Enrol/remove operations must return 403 for teachers.
     */

    public function test_teacher_cannot_enrol_student(): void
    {
        $response = $this->postJson("/api/school/cohorts/{$this->activeCohort->id}/enrolments", [
            'student_id' => $this->student->id,
        ], $this->teacherAuthHeaders());

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    public function test_teacher_cannot_remove_student(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $response = $this->deleteJson(
            "/api/school/cohorts/{$this->activeCohort->id}/enrolments/{$this->student->id}",
            [],
            $this->teacherAuthHeaders()
        );

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    public function test_teacher_can_read_enrolments(): void
    {
        $response = $this->getJson('/api/school/enrolments', $this->teacherAuthHeaders());

        $response->assertStatus(200);
    }
}
