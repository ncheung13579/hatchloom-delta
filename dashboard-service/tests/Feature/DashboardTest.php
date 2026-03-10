<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

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
        ]);

        $this->student = User::create([
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
        ]);

        $response = $this->getJson('/api/school/dashboard', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'school' => ['id', 'name'],
                'cohorts',
                'students' => ['total_enrolled', 'active_in_cohorts', 'not_assigned'],
                'statistics',
                'warnings',
            ]);
    }

    public function test_dashboard_handles_downstream_failure(): void
    {
        Http::fake([
            '*/api/school/experiences*' => Http::response('Server Error', 500),
            '*/api/school/enrolments/statistics*' => Http::response('Server Error', 500),
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
                'progress',
                'credentials',
            ]);
    }

    public function test_student_not_found_returns_404(): void
    {
        $response = $this->getJson('/api/school/dashboard/students/9999', $this->authHeaders());

        $response->assertStatus(404);
    }
}
