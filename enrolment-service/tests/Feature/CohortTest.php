<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Experience;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CohortTest extends TestCase
{
    use RefreshDatabase;

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
}
