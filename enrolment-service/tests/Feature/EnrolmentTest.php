<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\CohortEnrolment;
use App\Models\Experience;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'id' => 1,
            'name' => 'Admin User',
            'email' => 'admin@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_admin',
            'school_id' => $this->school->id,
        ]);

        User::create([
            'id' => 2,
            'name' => 'Ms. Smith',
            'email' => 'teacher1@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);

        $this->student = User::create([
            'id' => 4,
            'name' => 'Student 1',
            'email' => 'student1@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

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
}
