<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            'name' => 'Admin User',
            'email' => 'admin@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_admin',
            'school_id' => $this->school->id,
        ]); // auto-increment ID 1 → matches TOKEN_MAP 'test-admin-token'

        User::create([
            'name' => 'Teacher',
            'email' => 'teacher@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]); // auto-increment ID 2

        User::create([
            'name' => 'Filler',
            'email' => 'filler@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]); // auto-increment ID 3

        $this->student = User::create([
            'name' => 'Student 1',
            'email' => 'student1@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]); // auto-increment ID 4 → matches TOKEN_MAP 'test-student-token'
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
        $this->assertEquals(7, $data['summary']['active_ventures']); // From MockLaunchPadDataProvider
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

    // ── Authentication & Authorization ──────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/school/dashboard');

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

    public function test_hatchloom_teacher_cannot_access_dashboard(): void
    {
        $this->createHatchloomUsers();

        $response = $this->getJson('/api/school/dashboard', [
            'Authorization' => 'Bearer test-hatchloom-teacher-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    public function test_hatchloom_admin_cannot_access_dashboard(): void
    {
        $this->createHatchloomUsers();

        $response = $this->getJson('/api/school/dashboard', [
            'Authorization' => 'Bearer test-hatchloom-admin-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    public function test_student_role_cannot_read_school_wide_dashboard(): void
    {
        // Student user (ID 4) already created in setUp via auto-increment.
        // School-wide dashboard endpoints are admin/teacher only — students
        // must not see aggregated data for all students in the school.
        $response = $this->getJson('/api/school/dashboard', [
            'Authorization' => 'Bearer test-student-token',
        ]);

        $response->assertStatus(403);
    }

    public function test_student_can_read_own_drill_down(): void
    {
        $studentId = $this->student->id;

        Http::fake([
            "*/api/school/enrolments/students/{$studentId}" => Http::response([
                'student' => [
                    'id' => $studentId,
                    'name' => 'Student 1',
                    'email' => 'student1@ridgewood.edu',
                    'grade' => null,
                ],
                'enrolments' => [],
                'credentials' => [],
            ]),
        ]);

        $response = $this->getJson("/api/school/dashboard/students/{$studentId}", [
            'Authorization' => 'Bearer test-student-token',
        ]);

        $response->assertStatus(200);
    }

    public function test_can_get_student_drill_down(): void
    {
        $studentId = $this->student->id;

        Http::fake([
            "*/api/school/enrolments/students/{$studentId}" => Http::response([
                'student' => [
                    'id' => $studentId,
                    'name' => 'Student 1',
                    'email' => 'student1@ridgewood.edu',
                    'grade' => null,
                ],
                'enrolments' => [
                    [
                        'cohort_id' => 1,
                        'cohort_name' => 'Cohort A',
                        'experience_name' => 'Business Foundations',
                        'status' => 'enrolled',
                        'enrolled_at' => '2026-01-15T00:00:00Z',
                    ],
                ],
                'credentials' => [],
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
        $this->assertEquals(7, $data['summary']['active_ventures']); // From MockLaunchPadDataProvider
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
            "*/api/school/enrolments/students/{$studentId}" => Http::response([
                'student' => [
                    'id' => $studentId,
                    'name' => 'Student 1',
                    'email' => 'student1@ridgewood.edu',
                    'grade' => null,
                ],
                'enrolments' => [
                    ['cohort_id' => 1, 'cohort_name' => 'Cohort A', 'status' => 'enrolled'],
                ],
                'credentials' => [],
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

    // ── Widget endpoint tests ─────────────────────────────────

    /**
     * GET /widgets returns all 3 registered widget types in a single response.
     */
    public function test_widgets_returns_all_three_widget_types(): void
    {
        Http::fake([
            '*/api/school/experiences*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Business Foundations', 'status' => 'active'],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 1],
            ]),
            '*/api/school/cohorts' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Cohort A', 'status' => 'active', 'student_count' => 5],
                ],
            ]),
            '*/api/school/enrolments/statistics*' => Http::response([
                'total_students' => 10,
                'enrolled' => 8,
                'assigned' => 7,
                'not_assigned' => 3,
                'removed' => 0,
                'warnings' => [],
            ]),
            '*/api/school/enrolments' => Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0],
            ]),
        ]);

        $response = $this->getJson('/api/school/dashboard/widgets', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'widgets' => [
                    '*' => ['type', 'data'],
                ],
            ]);

        $data = $response->json();
        $this->assertCount(3, $data['widgets']);

        $widgetTypes = array_column($data['widgets'], 'type');
        $this->assertContains('cohort_summary', $widgetTypes);
        $this->assertContains('student_table', $widgetTypes);
        $this->assertContains('engagement_chart', $widgetTypes);
    }

    /**
     * GET /widgets/cohort_summary returns the correct data structure with
     * school info, cohort counts, student counts, statistics, and warnings.
     */
    public function test_widget_cohort_summary_returns_correct_structure(): void
    {
        Http::fake([
            '*/api/school/experiences*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Exp A', 'status' => 'active'],
                    ['id' => 2, 'name' => 'Exp B', 'status' => 'archived'],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 2],
            ]),
            '*/api/school/cohorts' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Cohort A', 'status' => 'active', 'student_count' => 5],
                    ['id' => 2, 'name' => 'Cohort B', 'status' => 'completed', 'student_count' => 3],
                    ['id' => 3, 'name' => 'Cohort C', 'status' => 'not_started', 'student_count' => 0],
                ],
            ]),
            '*/api/school/enrolments/statistics*' => Http::response([
                'total_students' => 10,
                'enrolled' => 8,
                'assigned' => 6,
                'not_assigned' => 4,
                'removed' => 1,
                'warnings' => [],
            ]),
        ]);

        $response = $this->getJson('/api/school/dashboard/widgets/cohort_summary', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type',
                'data' => [
                    'school' => ['id', 'name'],
                    'cohorts' => ['active', 'completed', 'upcoming', 'total'],
                    'students' => ['total_enrolled', 'active_in_cohorts', 'not_assigned'],
                    'statistics' => [
                        'enrolment_rate',
                        'credit_progress',
                        'timely_completion',
                        'problems_tackled',
                        'active_ventures',
                    ],
                    'warnings',
                ],
            ]);

        $data = $response->json();
        $this->assertEquals('cohort_summary', $data['type']);
        $this->assertEquals($this->school->id, $data['data']['school']['id']);
        $this->assertEquals('Ridgewood Academy', $data['data']['school']['name']);
        $this->assertEquals(1, $data['data']['cohorts']['active']);
        $this->assertEquals(1, $data['data']['cohorts']['completed']);
        $this->assertEquals(1, $data['data']['cohorts']['upcoming']);
        $this->assertEquals(3, $data['data']['cohorts']['total']);
        $this->assertEquals(8, $data['data']['students']['total_enrolled']);
        $this->assertEquals(6, $data['data']['students']['active_in_cohorts']);
        $this->assertEquals(7, $data['data']['statistics']['active_ventures']); // From MockLaunchPadDataProvider
    }

    /**
     * GET /widgets/student_table returns a student list with enrolment status
     * and cohort counts for each student in the school.
     */
    public function test_widget_student_table_returns_correct_structure(): void
    {
        Http::fake([
            '*/api/school/experiences*' => Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0],
            ]),
            '*/api/school/enrolments' => Http::response([
                'data' => [
                    [
                        'student_id' => $this->student->id,
                        'name' => 'Student 1',
                        'email' => 'student1@ridgewood.edu',
                        'cohort_assignments' => [
                            ['cohort_id' => 1, 'cohort_name' => 'Cohort A', 'status' => 'active'],
                        ],
                        'assignment_status' => 'assigned',
                        'last_active_at' => '2026-03-10T12:00:00Z',
                    ],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 1],
            ]),
        ]);

        $response = $this->getJson('/api/school/dashboard/widgets/student_table', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type',
                'data' => [
                    'total_students',
                    'students' => [
                        '*' => [
                            'student_id',
                            'name',
                            'email',
                            'status',
                            'cohort_count',
                            'active_cohort_count',
                            'last_active_at',
                        ],
                    ],
                ],
            ]);

        $data = $response->json();
        $this->assertEquals('student_table', $data['type']);
        $this->assertEquals(1, $data['data']['total_students']);
        $this->assertCount(1, $data['data']['students']);
        $this->assertEquals($this->student->id, $data['data']['students'][0]['student_id']);
        $this->assertEquals('Student 1', $data['data']['students'][0]['name']);
        $this->assertEquals('enrolled', $data['data']['students'][0]['status']);
        $this->assertEquals(1, $data['data']['students'][0]['cohort_count']);
        $this->assertEquals(1, $data['data']['students'][0]['active_cohort_count']);
    }

    /**
     * GET /widgets/engagement_chart returns engagement metrics with distribution
     * buckets, school averages, and per-student metrics.
     */
    public function test_widget_engagement_chart_returns_correct_structure(): void
    {
        Http::fake([
            '*/api/school/experiences*' => Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0],
            ]),
        ]);

        $response = $this->getJson('/api/school/dashboard/widgets/engagement_chart', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'type',
                'data' => [
                    'period',
                    'school_averages' => [
                        'avg_login_days',
                        'avg_completion_rate',
                        'active_student_count',
                    ],
                    'distribution' => ['low', 'moderate', 'good', 'excellent'],
                    'student_metrics' => [
                        '*' => [
                            'student_id',
                            'student_name',
                            'login_days',
                            'completion_rate',
                            'activities_completed',
                            'total_activities',
                            'last_active_at',
                            'engagement_level',
                        ],
                    ],
                ],
            ]);

        $data = $response->json();
        $this->assertEquals('engagement_chart', $data['type']);
        $this->assertEquals('last_30_days', $data['data']['period']);
        $this->assertNotEmpty($data['data']['student_metrics']);
        // Engagement level must be one of the valid classification values
        $validLevels = ['low', 'moderate', 'good', 'excellent'];
        foreach ($data['data']['student_metrics'] as $metric) {
            $this->assertContains($metric['engagement_level'], $validLevels);
        }
    }

    /**
     * GET /widgets/invalid_type returns 422 with the standard error format.
     */
    public function test_widget_invalid_type_returns_422(): void
    {
        Http::fake([
            '*/api/school/experiences*' => Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0],
            ]),
        ]);

        $response = $this->getJson('/api/school/dashboard/widgets/invalid_type', $this->authHeaders());

        $response->assertStatus(422)
            ->assertJsonStructure([
                'error',
                'message',
                'code',
            ]);

        $data = $response->json();
        $this->assertTrue($data['error']);
        $this->assertEquals('VALIDATION_ERROR', $data['code']);
        $this->assertStringContainsString('invalid_type', $data['message']);
    }

    /**
     * Widgets endpoints return 401 when no auth token is provided.
     */
    public function test_widgets_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/school/dashboard/widgets');
        $response->assertStatus(401);
    }

    /**
     * Single widget endpoint returns 401 when no auth token is provided.
     */
    public function test_single_widget_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/school/dashboard/widgets/cohort_summary');
        $response->assertStatus(401);
    }

    // ── Parent role tests ─────────────────────────────────────

    private function createParentUser(): void
    {
        // Parent user with ID 14 → matches TOKEN_MAP 'test-parent-token'
        \Illuminate\Support\Facades\DB::table('users')->insert([
            'id' => 14,
            'name' => 'Parent of Student 1',
            'email' => 'parent@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'parent',
            'school_id' => $this->school->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Link parent to their child via parent_student_links (many-to-many)
        \Illuminate\Support\Facades\DB::table('parent_student_links')->insert([
            ['parent_id' => 14, 'student_id' => $this->student->id],
        ]);
    }

    /**
     * Parent can view their own child's drill-down data.
     * The controller checks parent_student_links for the parent-child relationship.
     */
    public function test_parent_can_view_own_childs_drill_down(): void
    {
        $this->createParentUser();
        $studentId = $this->student->id;

        Http::fake([
            "*/api/school/enrolments/students/{$studentId}" => Http::response([
                'student' => [
                    'id' => $studentId,
                    'name' => 'Student 1',
                    'email' => 'student1@ridgewood.edu',
                    'grade' => null,
                ],
                'enrolments' => [],
                'credentials' => [],
            ]),
        ]);

        $response = $this->getJson("/api/school/dashboard/students/{$studentId}", [
            'Authorization' => 'Bearer test-parent-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('student.id', $studentId)
            ->assertJsonPath('student.name', 'Student 1');
    }

    /**
     * Parent cannot view a student who is not their child.
     * The controller returns 403 when parent_student_links has no matching row.
     */
    public function test_parent_cannot_view_other_students_drill_down(): void
    {
        $this->createParentUser();

        // Create another student (not the parent's child)
        $otherStudent = User::create([
            'name' => 'Other Student',
            'email' => 'other@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        $response = $this->getJson("/api/school/dashboard/students/{$otherStudent->id}", [
            'Authorization' => 'Bearer test-parent-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    /**
     * Parent cannot access the school-wide dashboard overview.
     * Only school_admin and school_teacher roles are allowed.
     */
    public function test_parent_cannot_access_dashboard_overview(): void
    {
        $this->createParentUser();

        $response = $this->getJson('/api/school/dashboard', [
            'Authorization' => 'Bearer test-parent-token',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Parent cannot access POS coverage reporting (school-wide data).
     */
    public function test_parent_cannot_access_pos_coverage(): void
    {
        $this->createParentUser();

        $response = $this->getJson('/api/school/dashboard/reporting/pos-coverage', [
            'Authorization' => 'Bearer test-parent-token',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Parent cannot access engagement rates reporting (school-wide data).
     */
    public function test_parent_cannot_access_engagement_rates(): void
    {
        $this->createParentUser();

        $response = $this->getJson('/api/school/dashboard/reporting/engagement', [
            'Authorization' => 'Bearer test-parent-token',
        ]);

        $response->assertStatus(403);
    }

    // ── Fix verification: exception logging on service failure ──

    public function test_dashboard_logs_warning_on_service_failure(): void
    {
        Log::shouldReceive('warning')
            ->atLeast()->times(1);

        Http::fake([
            '*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $response = $this->getJson('/api/school/dashboard', $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json();
        $warningTypes = array_column($data['warnings'], 'type');
        $this->assertContains('service_degraded', $warningTypes);
    }

    public function test_dashboard_logs_warning_on_connection_timeout(): void
    {
        Log::shouldReceive('warning')
            ->atLeast()->times(1);

        Http::fake([
            '*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $response = $this->getJson('/api/school/dashboard', $this->authHeaders());

        $response->assertStatus(200);
    }

    /**
     * Parent cannot access dashboard widgets (school-wide data).
     */
    public function test_parent_cannot_access_widgets(): void
    {
        $this->createParentUser();

        $response = $this->getJson('/api/school/dashboard/widgets', [
            'Authorization' => 'Bearer test-parent-token',
        ]);

        $response->assertStatus(403);
    }

    // ── Deep health check ─────────────────────────────────────

    /**
     * Verify that the health endpoint checks database connectivity and downstream services.
     */
    public function test_health_endpoint_includes_database_and_downstream(): void
    {
        Http::fake([
            '*/experiences/health' => Http::response(['status' => 'ok']),
            '*/enrolments/health' => Http::response(['status' => 'ok']),
        ]);

        $response = $this->getJson('/api/school/dashboard/health');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'service', 'timestamp', 'database', 'downstream'])
            ->assertJson([
                'status' => 'ok',
                'service' => 'dashboard',
                'database' => 'connected',
            ])
            ->assertJsonPath('downstream.experience-service', 'reachable')
            ->assertJsonPath('downstream.enrolment-service', 'reachable');
    }

    /**
     * Verify that health returns degraded when a downstream service is unreachable.
     */
    public function test_health_returns_degraded_when_downstream_unreachable(): void
    {
        Http::fake([
            '*/experiences/health' => Http::response(['status' => 'ok']),
            '*/enrolments/health' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $response = $this->getJson('/api/school/dashboard/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'degraded',
                'database' => 'connected',
            ])
            ->assertJsonPath('downstream.experience-service', 'reachable')
            ->assertJsonPath('downstream.enrolment-service', 'unreachable');
    }

    // ── Security headers ───────────────────────────────────────

    /**
     * Verify that all API responses include Content-Security-Policy,
     * X-Content-Type-Options, and X-Frame-Options headers.
     */
    public function test_api_responses_include_security_headers(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);

        $response = $this->getJson('/api/school/dashboard/health');

        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    // ── Global exception handler ──────────────────────────────

    /**
     * Verify that hitting a nonexistent route returns a JSON error envelope.
     */
    public function test_nonexistent_route_returns_json_404(): void
    {
        $response = $this->getJson('/api/school/nonexistent', $this->authHeaders());

        $response->assertStatus(404)
            ->assertJson([
                'error' => true,
                'code' => 'NOT_FOUND',
            ]);
    }

    /**
     * Verify that wrong HTTP method returns a JSON 405 error.
     */
    public function test_wrong_http_method_returns_json_405(): void
    {
        $response = $this->deleteJson('/api/school/dashboard', [], $this->authHeaders());

        $response->assertStatus(405)
            ->assertJson([
                'error' => true,
                'code' => 'METHOD_NOT_ALLOWED',
            ]);
    }
}
