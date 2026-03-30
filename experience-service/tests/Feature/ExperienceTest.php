<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Experience;
use App\Models\ExperienceCourse;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExperienceTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;
    private User $teacher;
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

        $this->teacher = User::create([
            'name' => 'Ms. Smith',
            'email' => 'teacher@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]); // auto-increment ID 2 → matches TOKEN_MAP 'test-teacher-token'
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-admin-token'];
    }

    private function teacherAuthHeaders(): array
    {
        return ['Authorization' => 'Bearer test-teacher-token'];
    }

    public function test_can_list_experiences(): void
    {
        Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Intro to business',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);
        Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Tech Explorers',
            'description' => 'Tech skills',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/school/experiences', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_can_search_experiences(): void
    {
        Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Intro to business',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);
        Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Tech Explorers',
            'description' => 'Tech skills',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/school/experiences?search=Business', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_can_create_experience(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => 'New Experience',
            'description' => 'A test experience',
            'course_ids' => [1, 2],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'New Experience']);

        $this->assertDatabaseHas('experiences', ['name' => 'New Experience']);
    }

    public function test_create_experience_validation_fails(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'description' => 'Missing name',
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    public function test_can_view_single_experience(): void
    {
        Http::fake([
            '*/api/school/cohorts*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Cohort A', 'status' => 'active', 'student_count' => 5],
                ],
            ]),
        ]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Intro to business',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/school/experiences/{$experience->id}", $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Business Foundations']);
    }

    public function test_experience_not_found_returns_404(): void
    {
        $response = $this->getJson('/api/school/experiences/9999', $this->authHeaders());

        $response->assertStatus(404);
    }

    public function test_school_scoping_isolates_data(): void
    {
        $otherSchool = School::create([
            'name' => 'Other School',
            'code' => 'OTHER',
            'is_active' => true,
        ]);

        // Create experience for other school (bypass scope by using DB directly)
        \Illuminate\Support\Facades\DB::table('experiences')->insert([
            'school_id' => $otherSchool->id,
            'name' => 'Other School Experience',
            'description' => 'Should not be visible',
            'status' => 'active',
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/school/experiences', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_can_get_experience_statistics(): void
    {
        Http::fake([
            '*/api/school/cohorts*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Cohort A', 'status' => 'active', 'student_count' => 6, 'capacity' => 25, 'removed_count' => 1],
                ],
            ]),
        ]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Intro to business',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/school/experiences/{$experience->id}/statistics", $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'experience_id',
                'enrolment' => ['total_students', 'active', 'removed'],
                'completion',
                'credit_progress',
            ]);
    }

    // ── Authentication & Authorization ──────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/school/experiences');

        $response->assertStatus(401)
            ->assertJsonFragment([
                'error' => true,
                'code' => 'UNAUTHENTICATED',
            ]);
    }

    public function test_invalid_token_returns_401(): void
    {
        $response = $this->getJson('/api/school/experiences', [
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

    public function test_hatchloom_teacher_cannot_access_experiences(): void
    {
        $this->createHatchloomUsers();

        $response = $this->getJson('/api/school/experiences', [
            'Authorization' => 'Bearer test-hatchloom-teacher-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    public function test_hatchloom_admin_cannot_access_experiences(): void
    {
        $this->createHatchloomUsers();

        $response = $this->getJson('/api/school/experiences', [
            'Authorization' => 'Bearer test-hatchloom-admin-token',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment(['code' => 'FORBIDDEN']);
    }

    public function test_student_role_can_read_experiences(): void
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

        // Students can read experiences (read-only access)
        $response = $this->getJson('/api/school/experiences', [
            'Authorization' => 'Bearer test-student-token',
        ]);
        $response->assertStatus(200);
    }

    public function test_student_role_cannot_create_experience(): void
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

        // Students cannot create experiences (write access blocked)
        $response = $this->postJson('/api/school/experiences', [
            'name' => 'Test Experience',
            'description' => 'Test',
            'course_ids' => [1],
        ], [
            'Authorization' => 'Bearer test-student-token',
        ]);
        $response->assertStatus(403)
            ->assertJsonFragment([
                'error' => true,
                'code' => 'FORBIDDEN',
            ]);
    }

    public function test_school_admin_can_create_experience(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => 'Admin Created',
            'description' => 'Admin should be allowed',
            'course_ids' => [1],
        ], $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'Admin Created']);
    }

    public function test_school_admin_can_update_experience(): void
    {
        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Test',
            'description' => 'Test',
            'status' => 'active',
            'created_by' => $this->teacher->id,
        ]);

        $response = $this->putJson("/api/school/experiences/{$experience->id}", [
            'name' => 'Admin Updated',
        ], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Admin Updated']);
    }

    public function test_school_admin_can_delete_experience(): void
    {
        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Test',
            'description' => 'Test',
            'status' => 'active',
            'created_by' => $this->teacher->id,
        ]);

        $response = $this->deleteJson("/api/school/experiences/{$experience->id}", [], $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Experience archived']);
    }

    public function test_can_search_students_in_experience(): void
    {
        // Search filtering is now delegated server-side to the Enrolment Service,
        // so the mock returns only the matching student (as the real service would).
        Http::fake([
            '*/api/school/enrolments*' => Http::response([
                'data' => [
                    ['student_id' => 10, 'name' => 'Alice Alpha', 'email' => 'alice@test.com', 'cohort_assignments' => [['cohort_id' => 1, 'cohort_name' => 'Cohort Alpha', 'status' => 'enrolled', 'enrolled_at' => '2026-03-01']], 'assignment_status' => 'assigned'],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 1],
            ]),
        ]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Intro to business',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/school/experiences/{$experience->id}/students?search=Alice", $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['student_name' => 'Alice Alpha']);
    }

    public function test_can_export_experience_students(): void
    {
        Http::fake([
            '*/api/school/enrolments*' => Http::response([
                'data' => [
                    ['student_id' => 10, 'name' => 'Alice Alpha', 'email' => 'alice@test.com', 'cohort_assignments' => [['cohort_id' => 1, 'cohort_name' => 'Cohort Alpha', 'status' => 'enrolled', 'enrolled_at' => '2026-03-01']], 'assignment_status' => 'assigned'],
                ],
            ]),
        ]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Intro to business',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->get("/api/school/experiences/{$experience->id}/students/export", $this->authHeaders());

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        // Validate CSV content structure and data
        $content = $response->streamedContent();
        $lines = explode("\n", trim($content));

        // Verify header row
        $headers = str_getcsv($lines[0]);
        $this->assertContains('student_name', $headers);
        $this->assertContains('student_email', $headers);
        $this->assertContains('cohort_name', $headers);
        $this->assertContains('status', $headers);
        $this->assertContains('enrolled_at', $headers);

        // Verify data row matches the mocked student
        $this->assertGreaterThanOrEqual(2, count($lines));
        $dataRow = str_getcsv($lines[1]);
        $row = array_combine($headers, $dataRow);
        $this->assertEquals('Alice Alpha', $row['student_name']);
        $this->assertEquals('alice@test.com', $row['student_email']);
        $this->assertEquals('Cohort Alpha', $row['cohort_name']);
        $this->assertEquals('enrolled', $row['status']);
    }

    public function test_can_get_student_detail_in_experience(): void
    {
        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Intro to business',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        Http::fake([
            '*/api/school/enrolments*' => Http::response([
                'data' => [
                    ['student_id' => 3, 'name' => 'Jane Doe', 'email' => 'jane@test.com', 'cohort_assignments' => [['cohort_id' => 1, 'cohort_name' => 'Cohort Alpha', 'status' => 'enrolled', 'enrolled_at' => '2026-03-01']], 'assignment_status' => 'assigned'],
                ],
            ]),
        ]);

        $response = $this->getJson("/api/school/experiences/{$experience->id}/students/3", $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'student_id',
                'student_name',
                'student_email',
                'experience_id',
                'cohort_id',
                'cohort_name',
                'status',
                'enrolled_at',
                'credits' => ['earned', 'total', 'progress'],
            ]);
    }

    public function test_can_get_experience_contents(): void
    {
        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Intro to business',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        ExperienceCourse::create([
            'experience_id' => $experience->id,
            'course_id' => 1,
            'sequence' => 1,
        ]);

        $response = $this->getJson("/api/school/experiences/{$experience->id}/contents", $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'experience_id',
                'courses' => [
                    ['id', 'name', 'sequence', 'blocks'],
                ],
            ]);
    }

    public function test_can_update_experience(): void
    {
        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Old Name',
            'description' => 'Old description',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->putJson("/api/school/experiences/{$experience->id}", [
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ], $this->teacherAuthHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Updated Name']);

        $this->assertDatabaseHas('experiences', [
            'id' => $experience->id,
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ]);
    }

    public function test_can_update_experience_courses(): void
    {
        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Intro to business',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        ExperienceCourse::create([
            'experience_id' => $experience->id,
            'course_id' => 1,
            'sequence' => 1,
        ]);

        // Replace courses entirely
        $response = $this->putJson("/api/school/experiences/{$experience->id}", [
            'course_ids' => [2, 3],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(200);

        // Old course should be gone, new ones present
        $this->assertDatabaseMissing('experience_courses', [
            'experience_id' => $experience->id,
            'course_id' => 1,
        ]);
        $this->assertDatabaseHas('experience_courses', [
            'experience_id' => $experience->id,
            'course_id' => 2,
            'sequence' => 1,
        ]);
        $this->assertDatabaseHas('experience_courses', [
            'experience_id' => $experience->id,
            'course_id' => 3,
            'sequence' => 2,
        ]);
    }

    public function test_can_delete_experience(): void
    {
        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'To Be Deleted',
            'description' => 'Will be archived',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->deleteJson("/api/school/experiences/{$experience->id}", [], $this->teacherAuthHeaders());

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Experience archived']);

        // Should be soft-deleted with archived status
        $this->assertDatabaseHas('experiences', [
            'id' => $experience->id,
            'status' => 'archived',
        ]);

        // Should not appear in the list anymore
        $listResponse = $this->getJson('/api/school/experiences', $this->authHeaders());
        $listResponse->assertJsonCount(0, 'data');
    }

    public function test_delete_nonexistent_experience_returns_404(): void
    {
        $response = $this->deleteJson('/api/school/experiences/9999', [], $this->teacherAuthHeaders());

        $response->assertStatus(404);
    }

    public function test_create_experience_with_invalid_course_ids_fails(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => 'Bad Experience',
            'description' => 'Has invalid courses',
            'course_ids' => [999, 888],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'One or more course IDs are invalid']);
    }

    public function test_update_experience_with_invalid_course_ids_fails(): void
    {
        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Valid Experience',
            'description' => 'Has valid courses',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->putJson("/api/school/experiences/{$experience->id}", [
            'course_ids' => [999],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'One or more course IDs are invalid']);
    }

    public function test_list_experiences_returns_empty_for_new_school(): void
    {
        $response = $this->getJson('/api/school/experiences', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    // ── Edge cases ─────────────────────────────────────────────

    public function test_create_experience_with_empty_name_fails(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => '',
            'description' => 'Has empty name',
            'course_ids' => [1],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    public function test_create_experience_with_very_long_name_fails(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => str_repeat('A', 256),
            'description' => 'Name exceeds 255 chars',
            'course_ids' => [1],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    public function test_create_experience_with_empty_course_ids_array_fails(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => 'No Courses',
            'description' => 'Empty course array',
            'course_ids' => [],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    public function test_search_is_case_insensitive(): void
    {
        Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Test',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/school/experiences?search=business', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    // ── Pagination ─────────────────────────────────────────────

    public function test_pagination_respects_per_page(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Experience::create([
                'school_id' => $this->school->id,
                'name' => "Experience {$i}",
                'description' => 'Test',
                'status' => 'active',
                'created_by' => $this->admin->id,
            ]);
        }

        $response = $this->getJson('/api/school/experiences?per_page=2', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    // ── Tighter assertions ─────────────────────────────────────

    public function test_create_experience_response_includes_courses(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => 'Full Response Check',
            'description' => 'Verify courses in response',
            'course_ids' => [1, 3],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id', 'name', 'description', 'status', 'courses', 'created_at',
            ]);

        $data = $response->json();
        $this->assertEquals('Full Response Check', $data['name']);
        $this->assertEquals('active', $data['status']);
        $this->assertCount(2, $data['courses']);
        $this->assertEquals('Intro to Entrepreneurship', $data['courses'][0]['name']);
        $this->assertEquals('Marketing Basics', $data['courses'][1]['name']);
    }

    public function test_list_experiences_response_includes_all_fields(): void
    {
        Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Test Experience',
            'description' => 'A test',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/school/experiences', $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json('data.0');
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('course_count', $data);
        $this->assertArrayHasKey('created_by', $data);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertEquals('Admin User', $data['created_by']);
    }

    public function test_experience_statistics_values_are_correct(): void
    {
        Http::fake([
            '*/api/school/cohorts*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Active Cohort', 'status' => 'active', 'student_count' => 10, 'capacity' => 20, 'removed_count' => 2],
                    ['id' => 2, 'name' => 'Completed Cohort', 'status' => 'completed', 'student_count' => 8, 'capacity' => 15, 'removed_count' => 1],
                ],
            ]),
        ]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Stats Test',
            'description' => 'Test',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/school/experiences/{$experience->id}/statistics", $this->authHeaders());

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals($experience->id, $data['experience_id']);
        $this->assertEquals(18, $data['enrolment']['total_students']);
        $this->assertEquals(10, $data['enrolment']['active']);
    }

    // ── Error envelope consistency ─────────────────────────────

    public function test_404_error_uses_standard_envelope(): void
    {
        $response = $this->getJson('/api/school/experiences/9999', $this->authHeaders());

        $response->assertStatus(404)
            ->assertJsonStructure(['error', 'message', 'code'])
            ->assertJsonFragment([
                'error' => true,
                'code' => 'NOT_FOUND',
            ]);
    }

    public function test_422_error_for_invalid_courses_uses_standard_envelope(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => 'Bad Courses',
            'description' => 'Invalid course IDs',
            'course_ids' => [999],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'code'])
            ->assertJsonFragment([
                'error' => true,
                'code' => 'VALIDATION_ERROR',
            ]);
    }

    // ── Pagination edge cases ─────────────────────────────────

    /**
     * Verify that requesting a page beyond the last page returns empty data.
     */
    public function test_pagination_page_beyond_last_returns_empty_data(): void
    {
        Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Only Experience',
            'description' => 'Test',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/school/experiences?page=999', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /**
     * Verify that per_page=1 returns exactly one record per page with correct meta.
     */
    public function test_pagination_per_page_one(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            Experience::create([
                'school_id' => $this->school->id,
                'name' => "Experience {$i}",
                'description' => 'Test',
                'status' => 'active',
                'created_by' => $this->admin->id,
            ]);
        }

        $response = $this->getJson('/api/school/experiences?per_page=1', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 3);
    }

    // ── Fix verification: per_page clamping ─────────────────

    public function test_per_page_clamped_to_max_100(): void
    {
        $response = $this->getJson('/api/school/experiences?per_page=500', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_per_page_clamped_to_min_1(): void
    {
        $response = $this->getJson('/api/school/experiences?per_page=0', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_per_page_negative_clamped_to_1(): void
    {
        $response = $this->getJson('/api/school/experiences?per_page=-10', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1);
    }

    // ── Fix verification: whitespace-only name rejection ──────

    public function test_create_experience_with_whitespace_only_name_fails(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => '   ',
            'description' => 'Valid description',
            'course_ids' => [1],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    public function test_update_experience_with_whitespace_only_name_fails(): void
    {
        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Valid Name',
            'description' => 'Valid description',
            'status' => 'active',
            'created_by' => $this->teacher->id,
        ]);

        $response = $this->putJson("/api/school/experiences/{$experience->id}", [
            'name' => '   ',
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    // ── Fix verification: description max length ──────────────

    public function test_create_experience_with_very_long_description_fails(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => 'Valid Name',
            'description' => str_repeat('A', 5001),
            'course_ids' => [1],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(422);
    }

    public function test_create_experience_with_max_description_succeeds(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'name' => 'Valid Name',
            'description' => str_repeat('A', 5000),
            'course_ids' => [1],
        ], $this->teacherAuthHeaders());

        $response->assertStatus(201);
    }

    // ── CSV data integrity: values exported verbatim ──────────

    public function test_csv_export_preserves_data_verbatim(): void
    {
        Http::fake([
            '*/api/school/enrolments*' => Http::response([
                'data' => [
                    [
                        'student_id' => 10,
                        'name' => '=CMD("calc")',
                        'email' => '+danger@school.test',
                        'cohort_assignments' => [
                            ['cohort_id' => 1, 'cohort_name' => '-Malicious Cohort', 'status' => 'enrolled', 'enrolled_at' => '2026-03-01'],
                        ],
                        'assignment_status' => 'assigned',
                    ],
                ],
            ]),
        ]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Test Experience',
            'description' => 'Test',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->get("/api/school/experiences/{$experience->id}/students/export", $this->authHeaders());

        $response->assertStatus(200);
        $content = $response->streamedContent();

        // Values must be exported exactly as stored — no mutation
        // fputcsv doubles internal quotes: "calc" → ""calc"" in CSV format
        $this->assertStringContainsString('=CMD(""calc"")', $content);
        $this->assertStringContainsString('+danger@school.test', $content);
        $this->assertStringContainsString('-Malicious Cohort', $content);
        // Verify no apostrophe prefix was added
        $this->assertStringNotContainsString("'=CMD", $content);
        $this->assertStringNotContainsString("'+danger", $content);
        $this->assertStringNotContainsString("'-Malicious", $content);
    }

    // ── Fix verification: experience students per_page clamping ──

    public function test_experience_students_per_page_clamped(): void
    {
        Http::fake([
            '*/api/school/enrolments*' => Http::response([
                'data' => [],
            ]),
        ]);

        $experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Test Experience',
            'description' => 'Test',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson("/api/school/experiences/{$experience->id}/students?per_page=500", $this->authHeaders());

        $response->assertStatus(200);
        // The per_page in meta should be clamped to 100
        $this->assertLessThanOrEqual(100, $response->json('meta.per_page'));
    }

    // ── Archived experience visibility ────────────────────────

    /**
     * Verify that archived (soft-deleted) experiences do not appear in the list endpoint.
     * Creates two experiences, archives one via the API, and verifies only the active one remains.
     */
    public function test_archived_experience_not_in_list(): void
    {
        Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Active One',
            'description' => 'Should be visible',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $toArchive = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Will Be Archived',
            'description' => 'Should not be visible after delete',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        // Archive via the API (sets status='archived' + soft-deletes)
        $this->deleteJson("/api/school/experiences/{$toArchive->id}", [], $this->teacherAuthHeaders())
            ->assertStatus(200);

        $response = $this->getJson('/api/school/experiences', $this->authHeaders());

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'Active One']);

        // Verify the archived one is NOT in the response
        $names = array_column($response->json('data'), 'name');
        $this->assertNotContains('Will Be Archived', $names);
    }

    // ── Deep health check ─────────────────────────────────────

    /**
     * Verify that the health endpoint checks database connectivity.
     */
    public function test_health_endpoint_includes_database_and_downstream(): void
    {
        Http::fake([
            '*/enrolments/health' => Http::response(['status' => 'ok']),
        ]);

        $response = $this->getJson('/api/school/experiences/health');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'service', 'timestamp', 'database', 'downstream'])
            ->assertJson([
                'status' => 'ok',
                'service' => 'experience',
                'database' => 'connected',
            ]);
    }

    /**
     * Verify that health returns degraded when downstream is unreachable.
     */
    public function test_health_returns_degraded_when_downstream_unreachable(): void
    {
        Http::fake([
            '*/enrolments/health' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $response = $this->getJson('/api/school/experiences/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'degraded',
                'database' => 'connected',
            ])
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

        $response = $this->getJson('/api/school/experiences/health');

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
        $response = $this->deleteJson('/api/school/experiences', [], $this->authHeaders());

        $response->assertStatus(405)
            ->assertJson([
                'error' => true,
                'code' => 'METHOD_NOT_ALLOWED',
            ]);
    }
}
