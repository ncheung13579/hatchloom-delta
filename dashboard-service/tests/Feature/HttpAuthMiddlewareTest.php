<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\HttpAuthMiddleware;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpAuthMiddlewareTest extends TestCase
{
    use DatabaseMigrations;

    private School $school;
    private User $admin;
    private HttpAuthMiddleware $middleware;

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

        $this->middleware = new HttpAuthMiddleware();
    }

    private function makeRequest(string $token = null): Request
    {
        $request = Request::create('/api/school/dashboard', 'GET');
        if ($token) {
            $request->headers->set('Authorization', "Bearer {$token}");
        }
        return $request;
    }

    private function passthrough(): \Closure
    {
        return fn (Request $r) => response()->json(['ok' => true], 200);
    }

    private function fakeQuebecSuccess(string $email = 'admin@ridgewood.edu', string $role = 'SCHOOL_ADMIN'): void
    {
        Http::fake([
            '*/auth/validate' => Http::response([
                'valid' => true,
                'userId' => 'uuid-abc-123',
                'role' => $role,
            ]),
            '*/profile/uuid-abc-123' => Http::response([
                'userId' => 'uuid-abc-123',
                'email' => $email,
                'firstName' => 'Admin',
                'lastName' => 'User',
                'role' => $role,
                'activeVentures' => 0,
            ]),
        ]);
    }

    public function test_successful_auth_flow(): void
    {
        $this->fakeQuebecSuccess();

        $response = $this->middleware->handle(
            $this->makeRequest('valid-jwt-token'),
            $this->passthrough()
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['ok' => true], json_decode($response->getContent(), true));
    }

    public function test_missing_bearer_token_returns_401(): void
    {
        $response = $this->middleware->handle(
            $this->makeRequest(),
            $this->passthrough()
        );

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('UNAUTHENTICATED', $data['code']);
    }

    public function test_invalid_token_returns_401(): void
    {
        Http::fake([
            '*/auth/validate' => Http::response(['error' => 'Invalid token'], 401),
        ]);

        $response = $this->middleware->handle(
            $this->makeRequest('bad-token'),
            $this->passthrough()
        );

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_token_valid_false_returns_401(): void
    {
        Http::fake([
            '*/auth/validate' => Http::response([
                'valid' => false,
                'userId' => null,
                'role' => null,
            ]),
        ]);

        $response = $this->middleware->handle(
            $this->makeRequest('expired-token'),
            $this->passthrough()
        );

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_missing_user_id_in_validation_returns_401(): void
    {
        Http::fake([
            '*/auth/validate' => Http::response([
                'valid' => true,
                'userId' => null,
                'role' => 'SCHOOL_ADMIN',
            ]),
        ]);

        $response = $this->middleware->handle(
            $this->makeRequest('partial-token'),
            $this->passthrough()
        );

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_quebec_service_unavailable_returns_401(): void
    {
        Http::fake([
            '*/auth/validate' => fn () => throw new \Exception('Connection refused'),
        ]);

        $response = $this->middleware->handle(
            $this->makeRequest('some-token'),
            $this->passthrough()
        );

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Authentication service unavailable', $data['message']);
    }

    public function test_profile_fetch_failure_returns_401(): void
    {
        Http::fake([
            '*/auth/validate' => Http::response([
                'valid' => true,
                'userId' => 'uuid-abc-123',
                'role' => 'SCHOOL_ADMIN',
            ]),
            '*/profile/uuid-abc-123' => Http::response(['error' => 'Not found'], 404),
        ]);

        $response = $this->middleware->handle(
            $this->makeRequest('valid-token'),
            $this->passthrough()
        );

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Unable to fetch user profile', $data['message']);
    }

    public function test_profile_service_exception_returns_401(): void
    {
        Http::fake([
            '*/auth/validate' => Http::response([
                'valid' => true,
                'userId' => 'uuid-abc-123',
                'role' => 'SCHOOL_ADMIN',
            ]),
            '*/profile/uuid-abc-123' => fn () => throw new \Exception('Timeout'),
        ]);

        $response = $this->middleware->handle(
            $this->makeRequest('valid-token'),
            $this->passthrough()
        );

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Authentication service unavailable', $data['message']);
    }

    public function test_profile_missing_email_returns_401(): void
    {
        Http::fake([
            '*/auth/validate' => Http::response([
                'valid' => true,
                'userId' => 'uuid-abc-123',
                'role' => 'SCHOOL_ADMIN',
            ]),
            '*/profile/uuid-abc-123' => Http::response([
                'userId' => 'uuid-abc-123',
                'firstName' => 'Admin',
                'role' => 'SCHOOL_ADMIN',
                // no email field
            ]),
        ]);

        $response = $this->middleware->handle(
            $this->makeRequest('valid-token'),
            $this->passthrough()
        );

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('User profile missing email', $data['message']);
    }

    public function test_no_local_user_matching_email_returns_401(): void
    {
        $this->fakeQuebecSuccess('unknown@example.com');

        $response = $this->middleware->handle(
            $this->makeRequest('valid-token'),
            $this->passthrough()
        );

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('User not found in local system', $data['message']);
    }

    public function test_student_role_forbidden_without_extra_roles(): void
    {
        $student = User::create([
            'name' => 'Student',
            'email' => 'student@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        $this->fakeQuebecSuccess('student@ridgewood.edu', 'STUDENT');

        $response = $this->middleware->handle(
            $this->makeRequest('student-token'),
            $this->passthrough()
        );

        $this->assertEquals(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('FORBIDDEN', $data['code']);
    }

    public function test_student_role_allowed_with_extra_role(): void
    {
        $student = User::create([
            'name' => 'Student',
            'email' => 'student@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        $this->fakeQuebecSuccess('student@ridgewood.edu', 'STUDENT');

        $response = $this->middleware->handle(
            $this->makeRequest('student-token'),
            $this->passthrough(),
            'student'
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Teachers are NOT allowed by default on dashboard endpoints (admin-only).
     * They must be explicitly added via middleware params on specific routes.
     */
    public function test_teacher_role_blocked_by_default(): void
    {
        $teacher = User::create([
            'name' => 'Teacher',
            'email' => 'teacher@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);

        $this->fakeQuebecSuccess('teacher@ridgewood.edu', 'SCHOOL_TEACHER');

        $response = $this->middleware->handle(
            $this->makeRequest('teacher-token'),
            $this->passthrough()
        );

        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Teachers ARE allowed when explicitly added via middleware params
     * (e.g., for the student drill-down endpoint).
     */
    public function test_teacher_role_allowed_when_explicitly_added(): void
    {
        $teacher = User::create([
            'name' => 'Teacher',
            'email' => 'teacher@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);

        $this->fakeQuebecSuccess('teacher@ridgewood.edu', 'SCHOOL_TEACHER');

        $response = $this->middleware->handle(
            $this->makeRequest('teacher-token'),
            $this->passthrough(),
            'school_teacher'
        );

        $this->assertEquals(200, $response->getStatusCode());
    }
}
