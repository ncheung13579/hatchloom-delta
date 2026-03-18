<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Experience;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class CohortTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;
    private School $school;
    private Experience $experience;

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

        $this->experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Test experience',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-admin-token'];
    }

    public function test_can_list_cohorts(): void
    {
        Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort A',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
            'capacity' => 25,
        ]);

        $response = $this->getJson('/api/school/cohorts', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_can_create_cohort(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => 'New Cohort',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
            'capacity' => 30,
        ], $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'New Cohort', 'status' => 'not_started']);
    }

    public function test_can_activate_cohort(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort A',
            'status' => 'not_started',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/activate", [], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'active']);
    }

    public function test_cannot_activate_completed_cohort(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort A',
            'status' => 'completed',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/activate", [], $this->authHeaders());

        $response->assertStatus(409);
    }

    public function test_can_complete_cohort(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort A',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/complete", [], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'completed']);
    }

    public function test_cannot_complete_not_started_cohort(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort B',
            'status' => 'not_started',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/complete", [], $this->authHeaders());

        $response->assertStatus(409);
    }

    public function test_create_cohort_validation_fails_missing_fields(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'name' => 'Incomplete Cohort',
            // Missing experience_id, start_date, end_date
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_can_update_cohort(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Old Cohort Name',
            'status' => 'not_started',
            'capacity' => 20,
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        $response = $this->putJson("/api/school/cohorts/{$cohort->id}", [
            'name' => 'Renamed Cohort',
            'capacity' => 35,
        ], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Renamed Cohort',
                'capacity' => 35,
            ]);
    }

    public function test_cohort_not_found_returns_404(): void
    {
        $response = $this->getJson('/api/school/cohorts/9999', $this->authHeaders());

        $response->assertStatus(404)
            ->assertJsonFragment(['code' => 'NOT_FOUND']);
    }

    public function test_can_filter_cohorts_by_status(): void
    {
        Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Active Cohort',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Completed Cohort',
            'status' => 'completed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
        ]);

        $response = $this->getJson('/api/school/cohorts?status=active', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Active Cohort']);
    }

    public function test_can_filter_cohorts_by_experience(): void
    {
        $experience2 = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Second Experience',
            'description' => 'Another experience',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort for Exp 1',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        Cohort::create([
            'experience_id' => $experience2->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort for Exp 2',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $response = $this->getJson(
            "/api/school/cohorts?experience_id={$this->experience->id}",
            $this->authHeaders()
        );

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Cohort for Exp 1']);
    }

    // ── Authentication & Authorization ──────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/school/cohorts');

        $response->assertStatus(401)
            ->assertJsonFragment([
                'error' => true,
                'code' => 'UNAUTHENTICATED',
            ]);
    }

    public function test_invalid_token_returns_401(): void
    {
        $response = $this->getJson('/api/school/cohorts', [
            'Authorization' => 'Bearer completely-invalid-token',
        ]);

        $response->assertStatus(401)
            ->assertJsonFragment([
                'error' => true,
                'code' => 'UNAUTHENTICATED',
            ]);
    }

    public function test_student_role_returns_403(): void
    {
        // Create filler users so auto-increment reaches ID 4
        // (setUp already created admin as ID 1)
        User::create(['name' => 'Teacher', 'email' => 'teacher@ridgewood.edu', 'password' => bcrypt('password'), 'role' => 'school_teacher', 'school_id' => $this->school->id]);
        User::create(['name' => 'Filler', 'email' => 'filler@ridgewood.edu', 'password' => bcrypt('password'), 'role' => 'school_teacher', 'school_id' => $this->school->id]);
        $student = User::create([
            'name' => 'Student 1',
            'email' => 'student1@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);
        // Verify auto-increment gave us ID 4 (matches TOKEN_MAP)
        $this->assertEquals(4, $student->id);

        $response = $this->getJson('/api/school/cohorts', [
            'Authorization' => 'Bearer test-student-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment([
                'error' => true,
                'code' => 'FORBIDDEN',
            ]);
    }

    // ── Edge cases ─────────────────────────────────────────────

    public function test_create_cohort_with_end_date_before_start_date_fails(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => 'Bad Dates Cohort',
            'start_date' => '2026-08-01',
            'end_date' => '2026-04-01',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_create_cohort_with_very_long_name_fails(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => str_repeat('X', 256),
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_create_cohort_with_zero_capacity_fails(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => 'Zero Cap Cohort',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
            'capacity' => 0,
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    public function test_create_cohort_with_nonexistent_experience_fails(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => 9999,
            'name' => 'Ghost Experience Cohort',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ], $this->authHeaders());

        $response->assertStatus(422);
    }

    // ── Tighter assertions ─────────────────────────────────────

    public function test_create_cohort_response_has_correct_values(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => 'Detailed Check Cohort',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
            'capacity' => 40,
        ], $this->authHeaders());

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('Detailed Check Cohort', $data['name']);
        $this->assertEquals('not_started', $data['status']);
        $this->assertEquals($this->experience->id, $data['experience_id']);
        $this->assertEquals(40, $data['capacity']);
        $this->assertEquals('2026-04-01', $data['start_date']);
        $this->assertEquals('2026-08-01', $data['end_date']);
        $this->assertNotNull($data['created_at']);
    }

    public function test_cohort_show_includes_student_count(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Count Check Cohort',
            'status' => 'active',
            'capacity' => 25,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $response = $this->getJson("/api/school/cohorts/{$cohort->id}", $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals(0, $data['student_count']);
        $this->assertEquals(25, $data['capacity']);
        $this->assertArrayHasKey('teacher_name', $data);
    }

    // ── State lifecycle ────────────────────────────────────────

    public function test_cannot_reactivate_completed_cohort(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Completed Cohort',
            'status' => 'completed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/activate", [], $this->authHeaders());

        $response->assertStatus(409)
            ->assertJsonStructure(['error', 'message', 'code'])
            ->assertJsonFragment(['code' => 'INVALID_STATE_TRANSITION']);
    }

    public function test_cannot_activate_already_active_cohort(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Already Active',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/activate", [], $this->authHeaders());

        $response->assertStatus(409);
    }

    // ── Error envelope consistency ─────────────────────────────

    public function test_cohort_errors_use_standard_envelope(): void
    {
        // 404
        $response = $this->getJson('/api/school/cohorts/9999', $this->authHeaders());
        $response->assertStatus(404)
            ->assertJsonStructure(['error', 'message', 'code'])
            ->assertJson(['error' => true, 'code' => 'NOT_FOUND']);

        // 409 — invalid state transition
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Error Check',
            'status' => 'completed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/complete", [], $this->authHeaders());
        $response->assertStatus(409)
            ->assertJsonStructure(['error', 'message', 'code'])
            ->assertJson(['error' => true, 'code' => 'INVALID_STATE_TRANSITION']);
    }
}
