<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;
    private User $student;
    private School $school;

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

        $this->student = User::create([
            'id' => 10,
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

    public function test_can_get_dashboard_overview(): void
    {
        Http::fake([
            '*/api/school/experiences*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Business Foundations', 'status' => 'active'],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 1],
            ]),
            '*/api/school/enrolments/statistics*' => Http::response([
                'total_students' => 10,
                'enrolled' => 8,
                'assigned' => 7,
                'not_assigned' => 3,
                'removed' => 1,
                'warnings' => [],
            ]),
            '*/api/school/cohorts' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Cohort A', 'status' => 'active', 'student_count' => 6],
                    ['id' => 2, 'name' => 'Cohort B', 'status' => 'not_started', 'student_count' => 0],
                    ['id' => 3, 'name' => 'Cohort C', 'status' => 'completed', 'student_count' => 4],
                ],
            ]),
        ]);

        $response = $this->getJson('/api/school/dashboard', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'school' => ['id', 'name'],
                'summary' => [
                    'problems_tackled',
                    'active_ventures',
                    'students',
                    'experiences',
                    'credit_progress',
                    'timely_completion',
                ],
                'cohorts' => ['active', 'completed', 'upcoming', 'total'],
                'students' => ['total_enrolled', 'active_in_cohorts', 'not_assigned'],
                'statistics',
                'warnings',
            ]);

        $data = $response->json();
        $this->assertEquals(1, $data['summary']['experiences']);
        $this->assertEquals(1, $data['summary']['active_ventures']);
        $this->assertEquals(10, $data['summary']['students']);
        $this->assertEquals(1, $data['cohorts']['active']);
        $this->assertEquals(1, $data['cohorts']['completed']);
        $this->assertEquals(1, $data['cohorts']['upcoming']);
        $this->assertEquals(3, $data['cohorts']['total']);
    }

    public function test_dashboard_handles_downstream_failure(): void
    {
        Http::fake([
            '*/api/school/experiences*' => Http::response('Server Error', 500),
            '*/api/school/enrolments/statistics*' => Http::response('Server Error', 500),
            '*/api/school/cohorts' => Http::response('Server Error', 500),
        ]);

        $response = $this->getJson('/api/school/dashboard', $this->authHeaders());

        $response->assertStatus(200);

        $data = $response->json();
        $warningTypes = array_column($data['warnings'], 'type');
        $this->assertContains('service_degraded', $warningTypes);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/school/dashboard');

        $response->assertStatus(401);
    }

    public function test_can_get_student_drill_down(): void
    {
        $studentId = $this->student->id;

        Http::fake([
            '*/api/school/enrolments*' => Http::response([
                'data' => [
                    [
                        'student_id' => $studentId,
                        'name' => 'Student 1',
                        'email' => 'student1@ridgewood.edu',
                        'cohort_assignments' => [
                            [
                                'cohort_id' => 1,
                                'cohort_name' => 'Cohort A',
                                'experience_name' => 'Business Foundations',
                                'status' => 'enrolled',
                                'enrolled_at' => '2026-01-15T00:00:00Z',
                            ],
                        ],
                        'assignment_status' => 'assigned',
                    ],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 1],
            ]),
        ]);

        $response = $this->getJson("/api/school/dashboard/students/{$studentId}", $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'student' => ['id', 'name', 'email'],
                'enrolments',
                'progress' => ['courses_completed', 'courses_in_progress', 'overall_completion'],
                'credentials' => [
                    ['id', 'type', 'name', 'issuing_course', 'earned_at', 'status'],
                ],
                'curriculum_mapping' => [
                    'business_studies' => ['area_name', 'requirements_met', 'total_requirements', 'coverage_percentage'],
                    'ctf_design_studies' => ['area_name', 'requirements_met', 'total_requirements', 'coverage_percentage'],
                    'calm' => ['area_name', 'requirements_met', 'total_requirements', 'coverage_percentage'],
                ],
            ]);

        $data = $response->json();
        $this->assertNotEmpty($data['credentials']);
        $this->assertNotEmpty($data['curriculum_mapping']['business_studies']['requirements_met']);
    }

    public function test_student_not_found_returns_404(): void
    {
        $response = $this->getJson('/api/school/dashboard/students/9999', $this->authHeaders());

        $response->assertStatus(404);
    }

    public function test_can_get_pos_coverage(): void
    {
        $response = $this->getJson('/api/school/dashboard/reporting/pos-coverage', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'school_id',
                'pos_areas',
                'student_coverage' => [
                    ['student_id', 'student_name', 'coverage' => [
                        'business_studies' => ['completed', 'total', 'percentage'],
                        'ctf_design_studies' => ['completed', 'total', 'percentage'],
                        'calm' => ['completed', 'total', 'percentage'],
                    ], 'overall_coverage'],
                ],
                'school_averages' => ['business_studies', 'ctf_design_studies', 'calm'],
            ]);

        $data = $response->json();
        $this->assertContains('Business Studies', $data['pos_areas']);
        $this->assertContains('CTF Design Studies', $data['pos_areas']);
        $this->assertContains('CALM', $data['pos_areas']);
    }

    public function test_can_get_engagement_rates(): void
    {
        $response = $this->getJson('/api/school/dashboard/reporting/engagement', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'school_id',
                'period',
                'school_averages' => ['avg_login_days', 'avg_completion_rate', 'active_student_count'],
                'student_engagement' => [
                    ['student_id', 'student_name', 'login_days_last_30', 'activities_completed', 'total_activities', 'completion_rate', 'last_active_at'],
                ],
            ]);
    }

    /**
     * Verify that when only the Experience Service is down but Enrolment Service
     * is up, the dashboard still returns partial data with a single warning.
     */
    public function test_dashboard_handles_partial_degradation(): void
    {
        Http::fake([
            '*/api/school/experiences*' => Http::response('Server Error', 500),
            '*/api/school/enrolments/statistics*' => Http::response([
                'total_students' => 5,
                'enrolled' => 3,
                'assigned' => 2,
                'not_assigned' => 3,
                'removed' => 0,
                'warnings' => [],
            ]),
            '*/api/school/cohorts' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Cohort A', 'status' => 'active', 'student_count' => 3],
                ],
            ]),
        ]);

        $response = $this->getJson('/api/school/dashboard', $this->authHeaders());

        $response->assertStatus(200);

        $data = $response->json();

        // Enrolment data should be present
        $this->assertEquals(3, $data['students']['total_enrolled']);
        $this->assertEquals(5, $data['summary']['students']);

        // Cohort data should be present
        $this->assertEquals(1, $data['cohorts']['active']);

        // Experience data should be degraded (zero)
        $this->assertEquals(0, $data['summary']['experiences']);

        // Should have exactly one service_degraded warning (Experience only)
        $degradedWarnings = array_filter($data['warnings'], fn($w) => $w['type'] === 'service_degraded');
        $this->assertCount(1, $degradedWarnings);
    }

    /**
     * Verify the dashboard works correctly when the school has no students
     * and no data — the zero-state should not crash.
     */
    public function test_dashboard_handles_empty_school(): void
    {
        // Remove the student seeded in setUp
        User::where('role', 'student')->delete();

        Http::fake([
            '*/api/school/experiences*' => Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0],
            ]),
            '*/api/school/enrolments/statistics*' => Http::response([
                'total_students' => 0,
                'enrolled' => 0,
                'assigned' => 0,
                'not_assigned' => 0,
                'removed' => 0,
                'warnings' => [],
            ]),
            '*/api/school/cohorts' => Http::response(['data' => []]),
        ]);

        $response = $this->getJson('/api/school/dashboard', $this->authHeaders());

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(0, $data['summary']['students']);
        $this->assertEquals(0, $data['summary']['experiences']);
        $this->assertEquals(0, $data['cohorts']['total']);
        $this->assertEquals(0, $data['students']['total_enrolled']);
        $this->assertEquals(0, $data['statistics']['enrolment_rate']);
        $this->assertEmpty($data['warnings']);
    }

    /**
     * Verify that a student from a different school cannot be viewed via
     * the drill-down endpoint — school scoping must block it.
     */
    public function test_student_drill_down_blocked_for_other_school(): void
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

        $response = $this->getJson(
            "/api/school/dashboard/students/{$otherStudent->id}",
            $this->authHeaders()
        );

        $response->assertStatus(404);
    }

    // ── Tighter assertions ─────────────────────────────────────

    public function test_dashboard_overview_values_are_correct(): void
    {
        Http::fake([
            '*/api/school/experiences*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Exp A', 'status' => 'active'],
                    ['id' => 2, 'name' => 'Exp B', 'status' => 'active'],
                    ['id' => 3, 'name' => 'Exp C', 'status' => 'archived'],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 3],
            ]),
            '*/api/school/enrolments/statistics*' => Http::response([
                'total_students' => 20,
                'enrolled' => 15,
                'assigned' => 12,
                'not_assigned' => 8,
                'removed' => 2,
                'warnings' => [
                    ['type' => 'unassigned_students', 'message' => '8 unassigned', 'severity' => 'warning'],
                ],
            ]),
            '*/api/school/cohorts' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'C1', 'status' => 'active', 'student_count' => 8],
                    ['id' => 2, 'name' => 'C2', 'status' => 'active', 'student_count' => 4],
                    ['id' => 3, 'name' => 'C3', 'status' => 'completed', 'student_count' => 6],
                    ['id' => 4, 'name' => 'C4', 'status' => 'not_started', 'student_count' => 0],
                ],
            ]),
        ]);

        $response = $this->getJson('/api/school/dashboard', $this->authHeaders());
        $response->assertStatus(200);
        $data = $response->json();

        // School info
        $this->assertEquals($this->school->id, $data['school']['id']);
        $this->assertEquals('Ridgewood Academy', $data['school']['name']);

        // Summary values
        $this->assertEquals(3, $data['summary']['experiences']);
        $this->assertEquals(2, $data['summary']['active_ventures']);
        $this->assertEquals(20, $data['summary']['students']);
        $this->assertEquals(6, $data['summary']['problems_tackled']); // 2 active * 3

        // Cohort breakdown
        $this->assertEquals(2, $data['cohorts']['active']);
        $this->assertEquals(1, $data['cohorts']['completed']);
        $this->assertEquals(1, $data['cohorts']['upcoming']);
        $this->assertEquals(4, $data['cohorts']['total']);

        // Student counts
        $this->assertEquals(15, $data['students']['total_enrolled']);
        $this->assertEquals(12, $data['students']['active_in_cohorts']);
        $this->assertEquals(8, $data['students']['not_assigned']);

        // Statistics
        $this->assertEquals(0.75, $data['statistics']['enrolment_rate']); // 15/20

        // Warnings should be merged from enrolment service
        $warningTypes = array_column($data['warnings'], 'type');
        $this->assertContains('unassigned_students', $warningTypes);
    }

    public function test_student_drill_down_response_values(): void
    {
        $studentId = $this->student->id;

        Http::fake([
            '*/api/school/enrolments*' => Http::response([
                'data' => [
                    [
                        'student_id' => $studentId,
                        'name' => 'Student 1',
                        'cohort_assignments' => [
                            ['cohort_id' => 1, 'cohort_name' => 'Cohort A', 'status' => 'enrolled'],
                        ],
                        'assignment_status' => 'assigned',
                    ],
                ],
                'meta' => ['total' => 1],
            ]),
        ]);

        $response = $this->getJson("/api/school/dashboard/students/{$studentId}", $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json();

        // Verify student identity
        $this->assertEquals($studentId, $data['student']['id']);
        $this->assertEquals('Student 1', $data['student']['name']);
        $this->assertEquals('student1@ridgewood.edu', $data['student']['email']);

        // Verify progress structure has values
        $this->assertArrayHasKey('courses_completed', $data['progress']);
        $this->assertArrayHasKey('courses_in_progress', $data['progress']);
        $this->assertIsFloat($data['progress']['overall_completion']);

        // Verify credentials from mock provider
        $this->assertNotEmpty($data['credentials']);
        $this->assertEquals('credential', $data['credentials'][0]['type']);
        $this->assertEquals('earned', $data['credentials'][0]['status']);

        // Verify curriculum mapping from mock provider
        $this->assertEquals('Business Studies', $data['curriculum_mapping']['business_studies']['area_name']);
        $this->assertEquals(8, $data['curriculum_mapping']['business_studies']['total_requirements']);
    }

    // ── Edge cases ─────────────────────────────────────────────

    public function test_pos_coverage_with_no_students(): void
    {
        User::where('role', 'student')->delete();

        $response = $this->getJson('/api/school/dashboard/reporting/pos-coverage', $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEmpty($data['student_coverage']);
        $this->assertArrayHasKey('school_averages', $data);
    }

    public function test_engagement_with_no_students(): void
    {
        User::where('role', 'student')->delete();

        $response = $this->getJson('/api/school/dashboard/reporting/engagement', $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEmpty($data['student_engagement']);
        $this->assertEquals(0, $data['school_averages']['active_student_count']);
    }

    public function test_invalid_token_returns_401(): void
    {
        $response = $this->getJson('/api/school/dashboard', [
            'Authorization' => 'Bearer completely-invalid-token',
        ]);

        $response->assertStatus(401);
    }
}
