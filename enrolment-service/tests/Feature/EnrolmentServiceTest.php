<?php

declare(strict_types=1);

// Tests for EnrolmentService — testing the business logic directly
// instead of going through HTTP like the other feature tests do.
// The idea is to hit the service methods and check the output is correct.

namespace Tests\Feature;

use App\Contracts\CredentialDataProviderInterface;
use App\Events\StudentEnrolled;
use App\Events\StudentRemoved;
use App\Models\Cohort;
use App\Models\CohortEnrolment;
use App\Models\Experience;
use App\Models\School;
use App\Models\User;
use App\Services\EnrolmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EnrolmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnrolmentService $service;
    private School $school;
    private User $admin;
    private Experience $experience;
    private Cohort $activeCohort;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // AUTH_MODE=mock is already set in phpunit.xml so the mock credential
        // provider gets bound automatically — no need to do it manually here
        $this->service = $this->app->make(EnrolmentService::class);

        $this->school = School::create([
            'name' => 'Ridgewood Academy',
            'code' => 'RIDGE',
            'is_active' => true,
        ]);

        // IDs need to match the TOKEN_MAP in MockAuthMiddleware
        // admin = ID 1, teacher = ID 2, filler = ID 3, student = ID 4
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_admin',
            'school_id' => $this->school->id,
        ]);

        User::create([
            'name' => 'Ms. Smith',
            'email' => 'teacher@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'school_teacher',
            'school_id' => $this->school->id,
        ]);

        // filler user just to push the auto increment so student lands on ID 4
        User::create([
            'name' => 'Filler User',
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
        ]);

        $this->experience = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Business Foundations',
            'description' => 'Test experience',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        // withoutGlobalScopes() skips the SchoolScope so we can create
        // cohorts directly without going through HTTP
        $this->activeCohort = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort A',
            'status' => 'active',
            'capacity' => 25,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        // EnrolmentService uses Auth::user() internally to scope by school
        // so we need to be logged in even though we're not hitting routes
        $this->actingAs($this->admin);
    }

    // ---------------------------------------------------------------
    // assignment status tests
    // these test determineAssignmentStatus() indirectly through
    // getEnrolmentOverview() since the method is private
    // ---------------------------------------------------------------

    public function test_assignment_status_is_assigned_when_enrolled_in_active_cohort(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $overview = $this->service->getEnrolmentOverview();
        $row = collect($overview->items())->firstWhere('student_id', $this->student->id);

        $this->assertEquals('assigned', $row['assignment_status']);
    }

    public function test_assignment_status_is_not_assigned_when_no_enrolments(): void
    {
        // student exists but hasn't been enrolled in anything yet
        $overview = $this->service->getEnrolmentOverview();
        $row = collect($overview->items())->firstWhere('student_id', $this->student->id);

        $this->assertEquals('not_assigned', $row['assignment_status']);
        $this->assertEmpty($row['cohort_assignments']);
    }

    public function test_assignment_status_is_removed_when_all_enrolments_are_removed(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
            'enrolled_at' => now()->subDays(5),
            'removed_at' => now(),
        ]);

        $overview = $this->service->getEnrolmentOverview();
        $row = collect($overview->items())->firstWhere('student_id', $this->student->id);

        $this->assertEquals('removed', $row['assignment_status']);
    }

    // edge case — being enrolled in a completed cohort shouldn't count as assigned
    // because the cohort is no longer running
    public function test_assignment_status_is_not_assigned_when_cohort_is_completed(): void
    {
        $completedCohort = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Old Cohort',
            'status' => 'completed',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
        ]);

        CohortEnrolment::create([
            'cohort_id' => $completedCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $overview = $this->service->getEnrolmentOverview();
        $row = collect($overview->items())->firstWhere('student_id', $this->student->id);

        // should be not_assigned not assigned since cohort is done
        $this->assertEquals('not_assigned', $row['assignment_status']);
    }

    // if student has one removed and one active enrolment, they should still
    // be assigned because at least one active enrolment exists
    public function test_assignment_status_is_assigned_when_mix_of_removed_and_enrolled(): void
    {
        $secondCohort = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort B',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-07-01',
        ]);

        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
            'enrolled_at' => now()->subDays(10),
            'removed_at' => now()->subDays(5),
        ]);

        CohortEnrolment::create([
            'cohort_id' => $secondCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $overview = $this->service->getEnrolmentOverview();
        $row = collect($overview->items())->firstWhere('student_id', $this->student->id);

        $this->assertEquals('assigned', $row['assignment_status']);
        $this->assertCount(2, $row['cohort_assignments']);
    }

    // ---------------------------------------------------------------
    // getEnrolmentOverview filter + pagination tests
    // ---------------------------------------------------------------

    public function test_overview_filter_by_experience_id_narrows_results(): void
    {
        $experience2 = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Digital Marketing',
            'description' => 'Second exp',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $cohort2 = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $experience2->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort DM',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        // student1 in experience1, student2 in experience2
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        CohortEnrolment::create([
            'cohort_id' => $cohort2->id,
            'student_id' => $student2->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        // filtering by experience1 should only return student1
        $result = $this->service->getEnrolmentOverview(null, 15, [
            'experience_id' => $this->experience->id,
        ]);

        $ids = collect($result->items())->pluck('student_id')->all();
        $this->assertContains($this->student->id, $ids);
        $this->assertNotContains($student2->id, $ids);
    }

    public function test_overview_filter_by_cohort_id_narrows_results(): void
    {
        $cohort2 = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort B',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-07-01',
        ]);

        $student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        CohortEnrolment::create([
            'cohort_id' => $cohort2->id,
            'student_id' => $student2->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        // filter by cohort2 — only student2 should show up
        $result = $this->service->getEnrolmentOverview(null, 15, [
            'cohort_id' => $cohort2->id,
        ]);

        $ids = collect($result->items())->pluck('student_id')->all();
        $this->assertContains($student2->id, $ids);
        $this->assertNotContains($this->student->id, $ids);
    }

    public function test_overview_search_is_case_insensitive(): void
    {
        // searching "STUDENT" should still find "Student 1"
        $result = $this->service->getEnrolmentOverview('STUDENT');

        $this->assertEquals(1, $result->total());
        $this->assertEquals('Student 1', $result->items()[0]['name']);
    }

    public function test_overview_pagination_respects_per_page(): void
    {
        User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        // 2 students exist but per_page=1 so should only get 1 back
        $result = $this->service->getEnrolmentOverview(null, 1);

        $this->assertCount(1, $result->items());
        $this->assertEquals(1, $result->perPage());
        $this->assertEquals(2, $result->total());
    }

    public function test_overview_excludes_non_student_roles(): void
    {
        // setUp has admin + 2 teachers + 1 student
        // only the student should show up in the overview
        $result = $this->service->getEnrolmentOverview();

        $this->assertEquals(1, $result->total());
        $user = User::find($result->items()[0]['student_id']);
        $this->assertEquals('student', $user->role);
    }

    // ---------------------------------------------------------------
    // calculateStatistics tests
    // ---------------------------------------------------------------

    public function test_statistics_counts_are_accurate(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $stats = $this->service->calculateStatistics();

        $this->assertEquals(1, $stats['total_students']);
        $this->assertEquals(1, $stats['enrolled']);
        $this->assertEquals(1, $stats['assigned']);
        $this->assertEquals(0, $stats['not_assigned']);
        $this->assertEquals(0, $stats['removed']);
    }

    public function test_statistics_not_assigned_counts_unenrolled_students(): void
    {
        // student has no enrolments so not_assigned should be 1
        $stats = $this->service->calculateStatistics();

        $this->assertEquals(1, $stats['total_students']);
        $this->assertEquals(0, $stats['assigned']);
        $this->assertEquals(1, $stats['not_assigned']);
    }

    public function test_statistics_removed_enrolments_counted_separately(): void
    {
        // removed enrolments shouldn't count toward enrolled or assigned
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
            'enrolled_at' => now()->subDays(5),
            'removed_at' => now(),
        ]);

        $stats = $this->service->calculateStatistics();

        $this->assertEquals(0, $stats['enrolled']);
        $this->assertEquals(0, $stats['assigned']);
        $this->assertEquals(1, $stats['removed']);
        $this->assertEquals(1, $stats['not_assigned']);
    }

    public function test_statistics_generates_unassigned_warning(): void
    {
        // student exists but no enrolments so should get an unassigned warning
        $stats = $this->service->calculateStatistics();

        $warningTypes = array_column($stats['warnings'], 'type');
        $this->assertContains('unassigned_students', $warningTypes);

        $warning = collect($stats['warnings'])->firstWhere('type', 'unassigned_students');
        $this->assertEquals('warning', $warning['severity']);
        $this->assertStringContainsString('1', $warning['message']);
    }

    public function test_statistics_no_unassigned_warning_when_all_assigned(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        // everyone is assigned so no warning should appear
        $stats = $this->service->calculateStatistics();

        $warningTypes = array_column($stats['warnings'], 'type');
        $this->assertNotContains('unassigned_students', $warningTypes);
    }

    // capacity=2, enrolled=2 → 100% → should get a capacity warning
    public function test_statistics_capacity_warning_at_100_percent(): void
    {
        $tinyCohort = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Tiny Cohort',
            'status' => 'active',
            'capacity' => 2,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        CohortEnrolment::create([
            'cohort_id' => $tinyCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        CohortEnrolment::create([
            'cohort_id' => $tinyCohort->id,
            'student_id' => $student2->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $stats = $this->service->calculateStatistics();

        $warningTypes = array_column($stats['warnings'], 'type');
        $this->assertContains('capacity_warning', $warningTypes);

        $warning = collect($stats['warnings'])->firstWhere('type', 'capacity_warning');
        $this->assertEquals('info', $warning['severity']);
        $this->assertStringContainsString('Tiny Cohort', $warning['message']);
    }

    // capacity=10, enrolled=9 → exactly 90% → should still trigger the warning
    public function test_statistics_capacity_warning_at_exactly_90_percent(): void
    {
        $cohort = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => '90 Percent Cohort',
            'status' => 'active',
            'capacity' => 10,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        for ($i = 0; $i < 9; $i++) {
            $s = User::create([
                'name' => "Bulk Student {$i}",
                'email' => "bulk{$i}@ridgewood.edu",
                'password' => bcrypt('password'),
                'role' => 'student',
                'school_id' => $this->school->id,
            ]);
            CohortEnrolment::create([
                'cohort_id' => $cohort->id,
                'student_id' => $s->id,
                'status' => 'enrolled',
                'enrolled_at' => now(),
            ]);
        }

        $stats = $this->service->calculateStatistics();

        $warningTypes = array_column($stats['warnings'], 'type');
        $this->assertContains('capacity_warning', $warningTypes);
    }

    // capacity=10, enrolled=8 → 80% → below threshold so no warning
    public function test_statistics_no_capacity_warning_below_threshold(): void
    {
        $cohort = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Spacious Cohort',
            'status' => 'active',
            'capacity' => 10,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        for ($i = 0; $i < 8; $i++) {
            $s = User::create([
                'name' => "Bulk Student {$i}",
                'email' => "bulk{$i}@ridgewood.edu",
                'password' => bcrypt('password'),
                'role' => 'student',
                'school_id' => $this->school->id,
            ]);
            CohortEnrolment::create([
                'cohort_id' => $cohort->id,
                'student_id' => $s->id,
                'status' => 'enrolled',
                'enrolled_at' => now(),
            ]);
        }

        $stats = $this->service->calculateStatistics();

        $warningTypes = array_column($stats['warnings'], 'type');
        $this->assertNotContains('capacity_warning', $warningTypes);
    }

    // null capacity means unlimited spots so no warning should fire
    public function test_statistics_no_capacity_warning_when_capacity_is_null(): void
    {
        Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Unlimited Cohort',
            'status' => 'active',
            'capacity' => null,
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $stats = $this->service->calculateStatistics();

        $warningTypes = array_column($stats['warnings'], 'type');
        $this->assertNotContains('capacity_warning', $warningTypes);
    }

    // completed cohorts should be ignored for capacity warnings
    public function test_statistics_completed_cohorts_excluded_from_capacity_warning(): void
    {
        $completedCohort = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Done Cohort',
            'status' => 'completed',
            'capacity' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01',
        ]);

        CohortEnrolment::create([
            'cohort_id' => $completedCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $stats = $this->service->calculateStatistics();

        $warningTypes = array_column($stats['warnings'], 'type');
        $this->assertNotContains('capacity_warning', $warningTypes);
    }

    // edge case — if there are literally no students everything should be 0
    public function test_statistics_with_zero_students_returns_all_zeros(): void
    {
        $this->student->delete();

        $stats = $this->service->calculateStatistics();

        $this->assertEquals(0, $stats['total_students']);
        $this->assertEquals(0, $stats['enrolled']);
        $this->assertEquals(0, $stats['assigned']);
        $this->assertEquals(0, $stats['not_assigned']);
        $this->assertEmpty($stats['warnings']);
    }

    // ---------------------------------------------------------------
    // getStudentDetail tests (drill-down)
    // ---------------------------------------------------------------

    public function test_student_detail_returns_correct_top_level_keys(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $detail = $this->service->getStudentDetail($this->student->id);

        // response should always have these three keys
        $this->assertNotNull($detail);
        $this->assertArrayHasKey('student', $detail);
        $this->assertArrayHasKey('enrolments', $detail);
        $this->assertArrayHasKey('credentials', $detail);
    }

    public function test_student_detail_student_block_has_correct_values(): void
    {
        $detail = $this->service->getStudentDetail($this->student->id);

        $this->assertEquals($this->student->id, $detail['student']['id']);
        $this->assertEquals('Student 1', $detail['student']['name']);
        $this->assertEquals('student1@ridgewood.edu', $detail['student']['email']);
        $this->assertArrayHasKey('grade', $detail['student']);
    }

    public function test_student_detail_grade_is_null_when_not_set(): void
    {
        // grade column exists but is nullable — should come back as null
        $detail = $this->service->getStudentDetail($this->student->id);

        $this->assertNull($detail['student']['grade']);
    }

    public function test_student_detail_grade_returns_correct_value_when_set(): void
    {
        $this->student->update(['grade' => 11]);

        $detail = $this->service->getStudentDetail($this->student->id);

        $this->assertEquals(11, $detail['student']['grade']);
    }

    public function test_student_detail_enrolment_contains_correct_fields(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $detail = $this->service->getStudentDetail($this->student->id);

        $this->assertCount(1, $detail['enrolments']);
        $enrolment = $detail['enrolments'][0];
        $this->assertEquals($this->activeCohort->id, $enrolment['cohort_id']);
        $this->assertEquals('Cohort A', $enrolment['cohort_name']);
        $this->assertEquals('Business Foundations', $enrolment['experience_name']);
        $this->assertEquals('enrolled', $enrolment['status']);
        $this->assertNotNull($enrolment['enrolled_at']);
    }

    // drill-down should show full history including removed enrolments
    public function test_student_detail_includes_both_enrolled_and_removed_enrolments(): void
    {
        $secondCohort = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort B',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-07-01',
        ]);

        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
            'enrolled_at' => now()->subDays(10),
            'removed_at' => now()->subDays(5),
        ]);

        CohortEnrolment::create([
            'cohort_id' => $secondCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $detail = $this->service->getStudentDetail($this->student->id);

        // both records should be in the response
        $this->assertCount(2, $detail['enrolments']);
        $statuses = array_column($detail['enrolments'], 'status');
        $this->assertContains('enrolled', $statuses);
        $this->assertContains('removed', $statuses);
    }

    public function test_student_detail_credential_block_has_correct_shape(): void
    {
        // just checking the shape since credentials come from a mock provider
        $detail = $this->service->getStudentDetail($this->student->id);

        $this->assertArrayHasKey('total_earned', $detail['credentials']);
        $this->assertArrayHasKey('in_progress', $detail['credentials']);
        $this->assertArrayHasKey('details', $detail['credentials']);
        $this->assertIsInt($detail['credentials']['total_earned']);
        $this->assertIsInt($detail['credentials']['in_progress']);
        $this->assertIsArray($detail['credentials']['details']);
    }

    // this verifies the strategy pattern works — swapping the provider
    // should change the output without touching EnrolmentService at all
    public function test_student_detail_uses_injected_credential_provider(): void
    {
        $this->app->bind(CredentialDataProviderInterface::class, function () {
            return new class implements CredentialDataProviderInterface {
                public function getStudentCredentialSummary(int $studentId): array
                {
                    return ['total_earned' => 99, 'in_progress' => 0, 'details' => []];
                }
            };
        });

        $service = $this->app->make(EnrolmentService::class);
        $detail = $service->getStudentDetail($this->student->id);

        $this->assertEquals(99, $detail['credentials']['total_earned']);
    }

    public function test_student_detail_returns_null_for_nonexistent_student(): void
    {
        $detail = $this->service->getStudentDetail(99999);

        $this->assertNull($detail);
    }

    // admin from school A should not be able to see students from school B
    public function test_student_detail_returns_null_for_student_in_different_school(): void
    {
        $otherSchool = School::create([
            'name' => 'Other Academy',
            'code' => 'OTHER',
            'is_active' => true,
        ]);

        $foreignStudent = User::create([
            'name' => 'Foreign Student',
            'email' => 'foreign@other.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $otherSchool->id,
        ]);

        $detail = $this->service->getStudentDetail($foreignStudent->id);

        $this->assertNull($detail);
    }

    // admins shouldn't be drillable via the student detail endpoint
    public function test_student_detail_returns_null_for_non_student_role(): void
    {
        $detail = $this->service->getStudentDetail($this->admin->id);

        $this->assertNull($detail);
    }

    // ---------------------------------------------------------------
    // exportEnrolmentList tests
    // ---------------------------------------------------------------

    public function test_export_returns_correct_column_keys(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $rows = $this->service->exportEnrolmentList();

        // check all expected CSV columns are present
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('student_name', $rows[0]);
        $this->assertArrayHasKey('student_email', $rows[0]);
        $this->assertArrayHasKey('cohort_name', $rows[0]);
        $this->assertArrayHasKey('experience_name', $rows[0]);
        $this->assertArrayHasKey('status', $rows[0]);
        $this->assertArrayHasKey('enrolled_at', $rows[0]);
        $this->assertArrayHasKey('removed_at', $rows[0]);
    }

    public function test_export_row_contains_correct_denormalized_values(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $row = $this->service->exportEnrolmentList()[0];

        $this->assertEquals('Student 1', $row['student_name']);
        $this->assertEquals('student1@ridgewood.edu', $row['student_email']);
        $this->assertEquals('Cohort A', $row['cohort_name']);
        $this->assertEquals('Business Foundations', $row['experience_name']);
        $this->assertEquals('enrolled', $row['status']);
        $this->assertNotNull($row['enrolled_at']);
        $this->assertNull($row['removed_at']); // not removed so this should be null
    }

    // export should include removed students too for the audit trail
    public function test_export_includes_removed_enrolments(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
            'enrolled_at' => now()->subDays(5),
            'removed_at' => now(),
        ]);

        $rows = $this->service->exportEnrolmentList();

        $this->assertCount(1, $rows);
        $this->assertEquals('removed', $rows[0]['status']);
        $this->assertNotNull($rows[0]['removed_at']);
    }

    public function test_export_filtered_by_cohort_id(): void
    {
        $cohort2 = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $this->experience->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort B',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-07-01',
        ]);

        $student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        CohortEnrolment::create([
            'cohort_id' => $cohort2->id,
            'student_id' => $student2->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        // filtering by cohort2 should only return student2's row
        $rows = $this->service->exportEnrolmentList(['cohort_id' => $cohort2->id]);

        $this->assertCount(1, $rows);
        $this->assertEquals('Student 2', $rows[0]['student_name']);
    }

    public function test_export_filtered_by_experience_id(): void
    {
        $experience2 = Experience::create([
            'school_id' => $this->school->id,
            'name' => 'Digital Marketing',
            'description' => 'Second exp',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $cohort2 = Cohort::withoutGlobalScopes()->create([
            'experience_id' => $experience2->id,
            'school_id' => $this->school->id,
            'name' => 'Cohort DM',
            'status' => 'active',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-01',
        ]);

        $student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@ridgewood.edu',
            'password' => bcrypt('password'),
            'role' => 'student',
            'school_id' => $this->school->id,
        ]);

        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        CohortEnrolment::create([
            'cohort_id' => $cohort2->id,
            'student_id' => $student2->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $rows = $this->service->exportEnrolmentList(['experience_id' => $experience2->id]);

        $this->assertCount(1, $rows);
        $this->assertEquals('Student 2', $rows[0]['student_name']);
        $this->assertEquals('Digital Marketing', $rows[0]['experience_name']);
    }

    public function test_export_returns_empty_when_no_enrolments(): void
    {
        $rows = $this->service->exportEnrolmentList();

        $this->assertEmpty($rows);
    }

    // ---------------------------------------------------------------
    // enrolStudent tests
    // ---------------------------------------------------------------

    public function test_enrol_student_returns_correct_enrolment_record(): void
    {
        $enrolment = $this->service->enrolStudent($this->activeCohort, $this->student->id);

        $this->assertInstanceOf(CohortEnrolment::class, $enrolment);
        $this->assertEquals($this->activeCohort->id, $enrolment->cohort_id);
        $this->assertEquals($this->student->id, $enrolment->student_id);
        $this->assertEquals('enrolled', $enrolment->status);
        $this->assertNotNull($enrolment->enrolled_at);
    }

    public function test_enrol_student_persists_to_database(): void
    {
        $this->service->enrolStudent($this->activeCohort, $this->student->id);

        $this->assertDatabaseHas('cohort_enrolments', [
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
        ]);
    }

    public function test_enrol_student_dispatches_student_enrolled_event(): void
    {
        Event::fake([StudentEnrolled::class]);

        $this->service->enrolStudent($this->activeCohort, $this->student->id);

        Event::assertDispatched(StudentEnrolled::class, function (StudentEnrolled $event) {
            return $event->enrolment->student_id === $this->student->id
                && $event->enrolment->cohort_id === $this->activeCohort->id
                && $event->cohort->id === $this->activeCohort->id;
        });
    }

    // ---------------------------------------------------------------
    // removeStudent tests
    // ---------------------------------------------------------------

    public function test_remove_student_soft_deletes_enrolment(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $result = $this->service->removeStudent($this->activeCohort, $this->student->id);

        // should be removed not hard deleted
        $this->assertNotNull($result);
        $this->assertEquals('removed', $result->status);
        $this->assertNotNull($result->removed_at);
    }

    public function test_remove_student_persists_removed_status_to_database(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        $this->service->removeStudent($this->activeCohort, $this->student->id);

        $this->assertDatabaseHas('cohort_enrolments', [
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
        ]);
    }

    public function test_remove_student_returns_null_when_not_enrolled(): void
    {
        // trying to remove someone who was never enrolled should return null
        $result = $this->service->removeStudent($this->activeCohort, $this->student->id);

        $this->assertNull($result);
    }

    public function test_remove_student_dispatches_student_removed_event(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'enrolled',
            'enrolled_at' => now(),
        ]);

        Event::fake([StudentRemoved::class]);

        $this->service->removeStudent($this->activeCohort, $this->student->id);

        Event::assertDispatched(StudentRemoved::class, function (StudentRemoved $event) {
            return $event->enrolment->student_id === $this->student->id
                && $event->enrolment->status === 'removed'
                && $event->cohort->id === $this->activeCohort->id
                && $event->removedAt instanceof \DateTimeInterface;
        });
    }

    // calling remove on an already-removed enrolment should do nothing
    // only enrolled records can be removed
    public function test_remove_student_does_not_remove_already_removed_enrolment(): void
    {
        CohortEnrolment::create([
            'cohort_id' => $this->activeCohort->id,
            'student_id' => $this->student->id,
            'status' => 'removed',
            'enrolled_at' => now()->subDays(5),
            'removed_at' => now(),
        ]);

        $result = $this->service->removeStudent($this->activeCohort, $this->student->id);

        $this->assertNull($result);
    }
}
