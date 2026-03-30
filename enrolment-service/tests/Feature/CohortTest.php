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
    private User $teacher;
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

        $this->teacher = User::create([
            'name' => 'Ms. Smith',
            'email' => 'teacher@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]); // auto-increment ID 2 → matches TOKEN_MAP 'test-teacher-token'

        $this->experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Test experience',
            'status' => 'active',
            'created_by' => $this->teacher->id,
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
        ], $this->teacherAuthHeaders());

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

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/activate", [], $this->teacherAuthHeaders());

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

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/activate", [], $this->teacherAuthHeaders());

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

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/complete", [], $this->teacherAuthHeaders());

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

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/complete", [], $this->teacherAuthHeaders());

        $response->assertStatus(409);
    }

    public function test_create_cohort_validation_fails_missing_fields(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'name' => 'Incomplete Cohort',
            // Missing experience_id, start_date, end_date
        ], $this->teacherAuthHeaders());

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
        ], $this->teacherAuthHeaders());

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

    private function createHatchloomUsers(): void
    {
        \Illuminate\Support\Facades\DB::table('users')->insert([
            ['id' => 15, 'name' => 'Hatchloom Course Builder', 'email' => 'teacher@hatchloom.com', 'password' => bcrypt('password'), 'role' => 'hatchloom_teacher', 'school_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 16, 'name' => 'Hatchloom Platform Admin', 'email' => 'admin@hatchloom.com', 'password' => bcrypt('password'), 'role' => 'hatchloom_admin', 'school_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_hatchloom_teacher_cannot_access_cohorts(): void
    {
        $this->createHatchloomUsers();

        $response = $this->getJson('/api/school/cohorts', [
            'Authorization' => 'Bearer test-hatchloom-teacher-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    public function test_hatchloom_admin_cannot_access_cohorts(): void
    {
        $this->createHatchloomUsers();

        $response = $this->getJson('/api/school/cohorts', [
            'Authorization' => 'Bearer test-hatchloom-admin-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    public function test_student_role_can_read_cohorts(): void
    {
        // Create filler user so auto-increment reaches ID 4
        // (setUp already created admin as ID 1, teacher as ID 2)
        User::create(['name' => 'Filler', 'email' => 'filler@ridgewood.edu', 'password' => bcrypt('password'), 'role' => 'school_teacher', 'school_id' => $this->school->id]);
        $student = User::create([
            'name' => 'Student 1',
            'email' => 'student1@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);
        $this->assertEquals(4, $student->id);

        // Students can read cohorts (read-only access)
        $response = $this->getJson('/api/school/cohorts', [
            'Authorization' => 'Bearer test-student-token',
        ]);
        $response->assertStatus(200);
    }

    public function test_student_role_cannot_create_cohort(): void
    {
        // Create filler user so auto-increment reaches ID 4
        // (setUp already created admin as ID 1, teacher as ID 2)
        User::create(['name' => 'Filler', 'email' => 'filler@ridgewood.edu', 'password' => bcrypt('password'), 'role' => 'school_teacher', 'school_id' => $this->school->id]);
        $student = User::create([
            'name' => 'Student 1',
            'email' => 'student1@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);
        $this->assertEquals(4, $student->id);

        // Students cannot create cohorts (write access blocked)
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => 'Test Cohort',
            'start_date' => now()->addDay()->format('Y-m-d'),
            'end_date' => now()->addMonth()->format('Y-m-d'),
        ], [
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
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    public function test_create_cohort_with_very_long_name_fails(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => str_repeat('X', 256),
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ], $this->teacherAuthHeaders());

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
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    public function test_create_cohort_with_nonexistent_experience_fails(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => 9999,
            'name' => 'Ghost Experience Cohort',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ], $this->teacherAuthHeaders());

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
        ], $this->teacherAuthHeaders());

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

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/activate", [], $this->teacherAuthHeaders());

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

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/activate", [], $this->teacherAuthHeaders());

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

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/complete", [], $this->teacherAuthHeaders());
        $response->assertStatus(409)
            ->assertJsonStructure(['error', 'message', 'code'])
            ->assertJson(['error' => true, 'code' => 'INVALID_STATE_TRANSITION']);
    }

    // ── School Admin permission ─────────────────────────────────

    public function test_school_admin_can_create_cohort(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => 'Admin Cohort',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ], $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'Admin Cohort', 'status' => 'not_started']);
    }

    public function test_school_admin_can_update_cohort(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Test Cohort',
            'status' => 'not_started',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        $response = $this->putJson("/api/school/cohorts/{$cohort->id}", [
            'name' => 'Admin Renamed',
        ], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Admin Renamed']);
    }

    public function test_school_admin_can_activate_cohort(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Test Cohort',
            'status' => 'not_started',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/activate", [], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'active']);
    }

    public function test_school_admin_can_complete_cohort(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Test Cohort',
            'status' => 'active',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/complete", [], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'completed']);
    }

    // ── Capacity default ─────────────────────────────────────

    /**
     * Creating a cohort without specifying capacity should succeed.
     * Capacity is nullable — no default is applied at the model level.
     */
    public function test_create_cohort_without_capacity_succeeds(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => 'No Capacity Cohort',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ], $this->teacherAuthHeaders());

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('No Capacity Cohort', $data['name']);
        $this->assertEquals('not_started', $data['status']);
    }

    // ── Fix verification: whitespace-only name rejection ──────

    public function test_create_cohort_with_whitespace_only_name_fails(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => '   ',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    public function test_update_cohort_with_whitespace_only_name_fails(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Valid Name',
            'status' => 'not_started',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        $response = $this->putJson("/api/school/cohorts/{$cohort->id}", [
            'name' => '   ',
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    public function test_create_cohort_with_tabs_only_name_fails(): void
    {
        $response = $this->postJson('/api/school/cohorts', [
            'experience_id' => $this->experience->id,
            'name' => "\t\t",
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    // ── Fix verification: state transitions with transaction ──

    public function test_activate_nonexistent_cohort_returns_404(): void
    {
        $response = $this->patchJson('/api/school/cohorts/9999/activate', [], $this->teacherAuthHeaders());

        $response->assertStatus(404)
            ->assertJsonFragment(['code' => 'NOT_FOUND']);
    }

    public function test_complete_nonexistent_cohort_returns_404(): void
    {
        $response = $this->patchJson('/api/school/cohorts/9999/complete', [], $this->teacherAuthHeaders());

        $response->assertStatus(404)
            ->assertJsonFragment(['code' => 'NOT_FOUND']);
    }

    public function test_activate_persists_status_change(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Persist Check',
            'status' => 'not_started',
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/activate", [], $this->teacherAuthHeaders());

        $response->assertStatus(200);
        $this->assertDatabaseHas('cohorts', ['id' => $cohort->id, 'status' => 'active']);
    }

    public function test_complete_persists_status_change(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Complete Check',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $response = $this->patchJson("/api/school/cohorts/{$cohort->id}/complete", [], $this->teacherAuthHeaders());

        $response->assertStatus(200);
        $this->assertDatabaseHas('cohorts', ['id' => $cohort->id, 'status' => 'completed']);
    }

    // ── Cohort show response completeness ─────────────────────

    /**
     * Verify GET /cohorts/{id} returns all required fields:
     * id, name, status, capacity, student_count, teacher_name, start_date, end_date, experience_id.
     */
    public function test_cohort_show_includes_all_required_fields(): void
    {
        $cohort = Cohort::create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Full Fields Cohort',
            'status' => 'active',
            'capacity' => 30,
            'teacher_id' => $this->teacher->id,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $response = $this->getJson("/api/school/cohorts/{$cohort->id}", $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json();

        // Verify all required fields are present
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('capacity', $data);
        $this->assertArrayHasKey('student_count', $data);
        $this->assertArrayHasKey('teacher_name', $data);
        $this->assertArrayHasKey('start_date', $data);
        $this->assertArrayHasKey('end_date', $data);
        $this->assertArrayHasKey('experience_id', $data);

        // Verify field values
        $this->assertEquals($cohort->id, $data['id']);
        $this->assertEquals('Full Fields Cohort', $data['name']);
        $this->assertEquals('active', $data['status']);
        $this->assertEquals(30, $data['capacity']);
        $this->assertEquals(0, $data['student_count']);
        $this->assertEquals('Ms. Smith', $data['teacher_name']);
        $this->assertEquals('2026-02-01', $data['start_date']);
        $this->assertEquals('2026-06-01', $data['end_date']);
        $this->assertEquals($this->experience->id, $data['experience_id']);
    }

    // ── Pagination edge cases ────────────────────────────────

    /**
     * Verify that requesting a page beyond the last page returns empty data.
     */
    public function test_pagination_page_beyond_last_returns_empty_data(): void
    {
        $response = $this->getJson('/api/school/cohorts?page=999', $this->authHeaders());

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));
    }

    // ── Deep health check ─────────────────────────────────────

    /**
     * Verify that the health endpoint checks database connectivity.
     */
    public function test_health_endpoint_includes_database_status(): void
    {
        $response = $this->getJson('/api/school/enrolments/health');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'service', 'timestamp', 'database'])
            ->assertJson([
                'status' => 'ok',
                'service' => 'enrolment',
                'database' => 'connected',
            ]);
    }

    // ── Security headers ───────────────────────────────────────

    /**
     * Verify that all API responses include Content-Security-Policy,
     * X-Content-Type-Options, and X-Frame-Options headers.
     */
    public function test_api_responses_include_security_headers(): void
    {
        $response = $this->getJson('/api/school/enrolments/health');

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
        $response = $this->deleteJson('/api/school/cohorts', [], $this->authHeaders());

        $response->assertStatus(405)
            ->assertJson([
                'error' => true,
                'code' => 'METHOD_NOT_ALLOWED',
            ]);
    }
}
