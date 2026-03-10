<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Experience;
use App\Models\ExperienceCourse;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExperienceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
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
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer test-admin-token'];
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
        ], $this->authHeaders());

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'New Experience']);

        $this->assertDatabaseHas('experiences', ['name' => 'New Experience']);
    }

    public function test_create_experience_validation_fails(): void
    {
        $response = $this->postJson('/api/school/experiences', [
            'description' => 'Missing name',
        ], $this->authHeaders());

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
                    ['id' => 1, 'name' => 'Cohort A', 'status' => 'active', 'student_count' => 6, 'capacity' => 25],
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

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/school/experiences');

        $response->assertStatus(401);
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
}
