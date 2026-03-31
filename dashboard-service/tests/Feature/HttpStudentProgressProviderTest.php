<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\HttpStudentProgressProvider;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpStudentProgressProviderTest extends TestCase
{
    use DatabaseMigrations;

    private HttpStudentProgressProvider $provider;

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

        $this->provider = new HttpStudentProgressProvider();
    }

    private function setRequestToken(string $token): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', "Bearer {$token}");
        $this->app->instance('request', $request);
    }

    private function sampleExperiences(): array
    {
        return [
            ['id' => 1, 'name' => 'Business Foundations', 'status' => 'active'],
            ['id' => 2, 'name' => 'Financial Literacy', 'status' => 'active'],
        ];
    }

    private function sampleStudents(): array
    {
        return [
            ['id' => 4, 'name' => 'Student 1'],
            ['id' => 5, 'name' => 'Student 2'],
        ];
    }

    // --- countProblemsTackled ---

    public function test_count_problems_tackled_success(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/progress/problems-tackled*' => Http::response(['count' => 12]),
        ]);

        $result = $this->provider->countProblemsTackled($this->sampleExperiences());
        $this->assertEquals(12, $result);
    }

    public function test_count_problems_tackled_empty_experiences(): void
    {
        $result = $this->provider->countProblemsTackled([]);
        $this->assertEquals(0, $result);
    }

    public function test_count_problems_tackled_no_token(): void
    {
        $request = Request::create('/api/test', 'GET');
        $this->app->instance('request', $request);

        $result = $this->provider->countProblemsTackled($this->sampleExperiences());
        $this->assertEquals(0, $result);
    }

    public function test_count_problems_tackled_api_failure(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/progress/problems-tackled*' => Http::response('Error', 500),
        ]);

        $result = $this->provider->countProblemsTackled($this->sampleExperiences());
        $this->assertEquals(0, $result);
    }

    public function test_count_problems_tackled_exception(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/progress/problems-tackled*' => fn () => throw new \Exception('Timeout'),
        ]);

        $result = $this->provider->countProblemsTackled($this->sampleExperiences());
        $this->assertEquals(0, $result);
    }

    // --- calculateCreditProgress ---

    public function test_calculate_credit_progress_success(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/progress/credit-progress*' => Http::response(['progress' => 0.65]),
        ]);

        $result = $this->provider->calculateCreditProgress($this->sampleExperiences());
        $this->assertEquals(0.65, $result);
    }

    public function test_calculate_credit_progress_empty_experiences(): void
    {
        $result = $this->provider->calculateCreditProgress([]);
        $this->assertEquals(0.0, $result);
    }

    public function test_calculate_credit_progress_api_failure(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/progress/credit-progress*' => Http::response('Error', 500),
        ]);

        $result = $this->provider->calculateCreditProgress($this->sampleExperiences());
        $this->assertEquals(0.0, $result);
    }

    // --- calculateTimelyCompletion ---

    public function test_calculate_timely_completion_success(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/progress/timely-completion*' => Http::response(['rate' => 0.82]),
        ]);

        $result = $this->provider->calculateTimelyCompletion(10, 8);
        $this->assertEquals(0.82, $result);
    }

    public function test_calculate_timely_completion_zero_enrolled(): void
    {
        $result = $this->provider->calculateTimelyCompletion(0, 0);
        $this->assertEquals(0.0, $result);
    }

    public function test_calculate_timely_completion_api_failure(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/progress/timely-completion*' => Http::response('Error', 500),
        ]);

        $result = $this->provider->calculateTimelyCompletion(10, 8);
        $this->assertEquals(0.0, $result);
    }

    // --- getPosCoverage ---

    public function test_get_pos_coverage_success(): void
    {
        $this->setRequestToken('valid-token');

        $expected = [
            'student_coverage' => [
                [
                    'student_id' => 4,
                    'student_name' => 'Student 1',
                    'coverage' => [
                        'business_studies' => ['completed' => 3, 'total' => 8, 'percentage' => 0.38],
                        'ctf_design_studies' => ['completed' => 2, 'total' => 7, 'percentage' => 0.29],
                        'calm' => ['completed' => 2, 'total' => 5, 'percentage' => 0.40],
                    ],
                    'overall_coverage' => 0.35,
                ],
            ],
            'school_averages' => [
                'business_studies' => 0.38,
                'ctf_design_studies' => 0.29,
                'calm' => 0.40,
            ],
        ];

        Http::fake([
            '*/api/progress/pos-coverage' => Http::response($expected),
        ]);

        $result = $this->provider->getPosCoverage([['id' => 4, 'name' => 'Student 1']]);
        $this->assertArrayHasKey('student_coverage', $result);
        $this->assertArrayHasKey('school_averages', $result);
        $this->assertEquals(0.38, $result['school_averages']['business_studies']);
    }

    public function test_get_pos_coverage_empty_students(): void
    {
        $result = $this->provider->getPosCoverage([]);
        $this->assertEmpty($result['student_coverage']);
        $this->assertEquals(0.0, $result['school_averages']['business_studies']);
    }

    public function test_get_pos_coverage_api_failure(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/progress/pos-coverage' => Http::response('Error', 500),
        ]);

        $result = $this->provider->getPosCoverage($this->sampleStudents());
        $this->assertEmpty($result['student_coverage']);
    }

    // --- getEngagementRates ---

    public function test_get_engagement_rates_success(): void
    {
        $this->setRequestToken('valid-token');

        $expected = [
            'student_engagement' => [
                [
                    'student_id' => 4,
                    'student_name' => 'Student 1',
                    'login_days_last_30' => 15,
                    'activities_completed' => 20,
                    'total_activities' => 25,
                    'completion_rate' => 0.80,
                    'last_active_at' => '2026-03-28T14:30:00+00:00',
                ],
            ],
            'school_averages' => [
                'avg_login_days' => 15.0,
                'avg_completion_rate' => 0.80,
                'active_student_count' => 1,
            ],
        ];

        Http::fake([
            '*/api/progress/engagement' => Http::response($expected),
        ]);

        $result = $this->provider->getEngagementRates([['id' => 4, 'name' => 'Student 1']]);
        $this->assertArrayHasKey('student_engagement', $result);
        $this->assertArrayHasKey('school_averages', $result);
        $this->assertEquals(0.80, $result['school_averages']['avg_completion_rate']);
    }

    public function test_get_engagement_rates_empty_students(): void
    {
        $result = $this->provider->getEngagementRates([]);
        $this->assertEmpty($result['student_engagement']);
        $this->assertEquals(0, $result['school_averages']['active_student_count']);
    }

    public function test_get_engagement_rates_api_failure(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/progress/engagement' => Http::response('Error', 500),
        ]);

        $result = $this->provider->getEngagementRates($this->sampleStudents());
        $this->assertEmpty($result['student_engagement']);
    }

    public function test_get_engagement_rates_exception(): void
    {
        $this->setRequestToken('valid-token');

        Http::fake([
            '*/api/progress/engagement' => fn () => throw new \Exception('Connection refused'),
        ]);

        $result = $this->provider->getEngagementRates($this->sampleStudents());
        $this->assertEmpty($result['student_engagement']);
        $this->assertEquals(0, $result['school_averages']['active_student_count']);
    }
}
