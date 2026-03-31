<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\HttpLaunchPadDataProvider;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpLaunchPadDataProviderTest extends TestCase
{
    use DatabaseMigrations;

    private School $school;
    private User $student;
    private HttpLaunchPadDataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Ridgewood Academy',
            'code' => 'RIDGE',
            'is_active' => true,
        ]);

        // Create admin (ID 1) so request()->bearerToken() works with mock auth
        User::create([
            'name' => 'Admin',
            'email' => 'admin@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_admin',
            'school_id' => $this->school->id,
        ]);

        // Filler (ID 2)
        User::create([
            'name' => 'Teacher',
            'email' => 'teacher@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);

        // Filler (ID 3)
        User::create([
            'name' => 'Filler',
            'email' => 'filler@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);

        $this->student = User::create([
            'name' => 'Student 1',
            'email' => 'student1@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]); // ID 4

        $this->provider = new HttpLaunchPadDataProvider();
    }

    private function setRequestToken(string $token): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");
        $this->app->instance('request', $request);
    }

    public function test_count_active_ventures_single_page(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/profile*' => Http::response([
                'content' => [
                    ['email' => 'student1@ridgewood.edu', 'role' => 'STUDENT', 'activeVentures' => 3],
                    ['email' => 'student2@ridgewood.edu', 'role' => 'STUDENT', 'activeVentures' => 2],
                    ['email' => 'admin@ridgewood.edu', 'role' => 'SCHOOL_ADMIN', 'activeVentures' => 0],
                ],
                'last' => true,
            ]),
        ]);

        $count = $this->provider->countActiveVentures($this->school->id);
        $this->assertEquals(5, $count);
    }

    public function test_count_active_ventures_multiple_pages(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/profile?page=0*' => Http::response([
                'content' => [
                    ['email' => 's1@test.com', 'role' => 'STUDENT', 'activeVentures' => 3],
                ],
                'last' => false,
            ]),
            '*/profile?page=1*' => Http::response([
                'content' => [
                    ['email' => 's2@test.com', 'role' => 'STUDENT', 'activeVentures' => 4],
                ],
                'last' => true,
            ]),
        ]);

        $count = $this->provider->countActiveVentures($this->school->id);
        $this->assertEquals(7, $count);
    }

    public function test_count_active_ventures_skips_non_students(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/profile*' => Http::response([
                'content' => [
                    ['email' => 'admin@test.com', 'role' => 'SCHOOL_ADMIN', 'activeVentures' => 5],
                    ['email' => 'teacher@test.com', 'role' => 'SCHOOL_TEACHER', 'activeVentures' => 3],
                    ['email' => 'student@test.com', 'role' => 'STUDENT', 'activeVentures' => 2],
                ],
                'last' => true,
            ]),
        ]);

        $count = $this->provider->countActiveVentures($this->school->id);
        $this->assertEquals(2, $count);
    }

    public function test_count_active_ventures_no_token_returns_zero(): void
    {
        // No token set on request
        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $count = $this->provider->countActiveVentures($this->school->id);
        $this->assertEquals(0, $count);
    }

    public function test_count_active_ventures_api_failure_returns_zero(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/profile*' => Http::response(['error' => 'Server error'], 500),
        ]);

        $count = $this->provider->countActiveVentures($this->school->id);
        $this->assertEquals(0, $count);
    }

    public function test_count_active_ventures_exception_returns_zero(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/profile*' => fn () => throw new \Exception('Connection refused'),
        ]);

        $count = $this->provider->countActiveVentures($this->school->id);
        $this->assertEquals(0, $count);
    }

    public function test_get_student_ventures_success(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/profile*' => Http::response([
                'content' => [
                    ['email' => 'other@test.com', 'role' => 'STUDENT', 'activeVentures' => 1],
                    ['email' => 'student1@ridgewood.edu', 'role' => 'STUDENT', 'activeVentures' => 5],
                ],
                'last' => true,
            ]),
        ]);

        $result = $this->provider->getStudentVentures($this->student->id);

        $this->assertEquals(5, $result['active']);
        $this->assertEquals(0, $result['completed']);
        $this->assertIsArray($result['ventures']);
        $this->assertEmpty($result['ventures']);
    }

    public function test_get_student_ventures_no_token_returns_defaults(): void
    {
        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $result = $this->provider->getStudentVentures($this->student->id);

        $this->assertEquals(0, $result['active']);
        $this->assertEquals(0, $result['completed']);
        $this->assertEmpty($result['ventures']);
    }

    public function test_get_student_ventures_unknown_student_returns_defaults(): void
    {
        $this->setRequestToken('valid-token');

        $result = $this->provider->getStudentVentures(9999);

        $this->assertEquals(0, $result['active']);
        $this->assertEquals(0, $result['completed']);
        $this->assertEmpty($result['ventures']);

        Http::assertNothingSent();
    }

    public function test_get_student_ventures_no_matching_profile_returns_defaults(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/profile*' => Http::response([
                'content' => [
                    ['email' => 'someone-else@test.com', 'role' => 'STUDENT', 'activeVentures' => 3],
                ],
                'last' => true,
            ]),
        ]);

        $result = $this->provider->getStudentVentures($this->student->id);

        $this->assertEquals(0, $result['active']);
        $this->assertEquals(0, $result['completed']);
        $this->assertEmpty($result['ventures']);
    }

    public function test_get_student_ventures_api_failure_returns_defaults(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/profile*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $result = $this->provider->getStudentVentures($this->student->id);

        $this->assertEquals(0, $result['active']);
        $this->assertEquals(0, $result['completed']);
        $this->assertEmpty($result['ventures']);
    }
}
