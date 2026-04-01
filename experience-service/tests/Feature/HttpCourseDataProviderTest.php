<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\HttpCourseDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpCourseDataProviderTest extends TestCase
{
    use RefreshDatabase;

    private HttpCourseDataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        School::create([
            'name' => 'Ridgewood Academy',
            'code' => 'RIDGE',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Admin',
            'email' => 'admin@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_admin',
            'school_id' => 1,
        ]);

        $this->provider = new HttpCourseDataProvider();
    }

    private function setRequestToken(string $token): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");
        $this->app->instance('request', $request);
    }

    private function sampleCourses(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Intro to Entrepreneurship',
                'description' => 'Learn the basics.',
                'blocks' => [
                    ['id' => 101, 'name' => 'What is a Business?', 'status' => 'complete'],
                    ['id' => 102, 'name' => 'Business Models', 'status' => 'active'],
                ],
            ],
            [
                'id' => 2,
                'name' => 'Financial Literacy',
                'description' => 'Understanding money.',
                'blocks' => [
                    ['id' => 201, 'name' => 'Budgeting Basics', 'status' => 'complete'],
                ],
            ],
        ];
    }

    public function test_get_all_courses_success(): void
    {
        $this->setRequestToken('valid-token');
        $courses = $this->sampleCourses();

        Http::fake([
            '*/api/courses' => Http::response($courses),
        ]);

        $result = $this->provider->getAllCourses();
        $this->assertCount(2, $result);
        $this->assertEquals('Intro to Entrepreneurship', $result[0]['name']);
    }

    public function test_get_all_courses_with_data_wrapper(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/courses' => Http::response(['data' => $this->sampleCourses()]),
        ]);

        $result = $this->provider->getAllCourses();
        $this->assertCount(2, $result);
    }

    public function test_get_all_courses_no_token_returns_empty(): void
    {
        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $result = $this->provider->getAllCourses();
        $this->assertEmpty($result);
    }

    public function test_get_all_courses_api_failure_returns_empty(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/courses' => Http::response('Server Error', 500),
        ]);

        $result = $this->provider->getAllCourses();
        $this->assertEmpty($result);
    }

    public function test_get_all_courses_exception_returns_empty(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/courses' => fn () => throw new \Exception('Connection refused'),
        ]);

        $result = $this->provider->getAllCourses();
        $this->assertEmpty($result);
    }

    public function test_get_course_success(): void
    {
        $this->setRequestToken('valid-token');
        $course = $this->sampleCourses()[0];

        Http::fake([
            '*/api/courses/1' => Http::response($course),
        ]);

        $result = $this->provider->getCourse(1);
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['id']);
        $this->assertEquals('Intro to Entrepreneurship', $result['name']);
    }

    public function test_get_course_not_found_returns_null(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/courses/999' => Http::response('Not found', 404),
        ]);

        $result = $this->provider->getCourse(999);
        $this->assertNull($result);
    }

    public function test_get_course_no_token_returns_null(): void
    {
        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $result = $this->provider->getCourse(1);
        $this->assertNull($result);
    }

    public function test_course_exists_returns_true(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/courses/1' => Http::response($this->sampleCourses()[0]),
        ]);

        $this->assertTrue($this->provider->courseExists(1));
    }

    public function test_course_exists_returns_false_for_missing(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/courses/999' => Http::response('Not found', 404),
        ]);

        $this->assertFalse($this->provider->courseExists(999));
    }

    public function test_get_courses_by_ids_success(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/courses*' => Http::response($this->sampleCourses()),
        ]);

        $result = $this->provider->getCoursesByIds([1, 2]);
        $this->assertCount(2, $result);
    }

    public function test_get_courses_by_ids_empty_array_returns_empty(): void
    {
        $this->setRequestToken('valid-token');

        $result = $this->provider->getCoursesByIds([]);
        $this->assertEmpty($result);

        Http::assertNothingSent();
    }

    public function test_get_courses_by_ids_api_failure_returns_empty(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/courses*' => Http::response('Error', 500),
        ]);

        $result = $this->provider->getCoursesByIds([1, 2]);
        $this->assertEmpty($result);
    }
}
