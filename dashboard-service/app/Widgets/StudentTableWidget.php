<?php

/**
 * StudentTableWidget — Student roster with enrolment status for the dashboard.
 *
 * Design pattern: Factory Method (concrete product)
 *   This is a concrete DashboardWidget created by DashboardWidgetFactory when
 *   the type is 'student_table'. It powers the student list section on Screen 300
 *   where admins can see all students in their school with their enrolment status.
 *
 * Data sources:
 *   - Local DB: Queries the users table for students in the school (school_id scoped)
 *   - Enrolment Service (port 8003): Fetches enrolment/cohort assignment data
 *
 * Cross-service HTTP calls:
 *   Makes ONE HTTP call to GET {enrolment_service}/api/school/enrolments
 *   Expected response: { "data": [ { "student_id": 3, "cohort_assignments": [...], ... }, ... ] }
 *   On failure: students are still shown but with 'unknown' enrolment status.
 *
 * Status derivation logic:
 *   - 'enrolled'   — Student is in at least one active cohort
 *   - 'inactive'   — Student is in cohorts, but none are active (all completed/not_started)
 *   - 'unassigned' — Student has no cohort assignments at all
 *   - 'unknown'    — Enrolment Service was unavailable; we can't determine status
 *
 * @see \App\Contracts\DashboardWidget          Interface this implements
 * @see \App\Factories\DashboardWidgetFactory   Factory that instantiates this
 */

declare(strict_types=1);

namespace App\Widgets;

use App\Contracts\DashboardWidget;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class StudentTableWidget implements DashboardWidget
{
    private string $token;
    private int $schoolId;

    public function __construct(array $context)
    {
        $this->token = $context['token'];
        $this->schoolId = $context['school_id'];
    }

    public function getData(): array
    {
        // Query all students in this school from the local users table.
        // School scoping is enforced by the WHERE school_id clause.
        $students = User::where('school_id', $this->schoolId)
            ->where('role', 'student')
            ->get();

        $enrolmentData = $this->fetchEnrolmentData();

        // Build a lookup map indexed by student_id for O(1) access when
        // iterating over students. Without this, we'd need O(n*m) nested loops.
        $enrolmentByStudentId = [];
        foreach ($enrolmentData as $record) {
            $studentId = $record['student_id'] ?? null;
            if ($studentId !== null) {
                $enrolmentByStudentId[$studentId] = $record;
            }
        }

        $rows = $students->map(function (User $student) use ($enrolmentByStudentId): array {
            $enrolment = $enrolmentByStudentId[$student->id] ?? null;
            $cohortAssignments = $enrolment['cohort_assignments'] ?? [];
            $activeCohorts = array_filter(
                $cohortAssignments,
                fn(array $c): bool => ($c['status'] ?? '') === 'active'
            );

            // Derive the student's display status from their cohort assignments.
            // Priority: active cohorts > any cohorts > no cohorts
            $status = 'unassigned';
            if (count($activeCohorts) > 0) {
                $status = 'enrolled';
            } elseif (count($cohortAssignments) > 0) {
                $status = 'inactive';
            }

            return [
                'student_id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                // If the Enrolment Service was unavailable ($enrolment is null),
                // we show 'unknown' instead of a potentially incorrect status
                'status' => $enrolment !== null ? $status : 'unknown',
                'cohort_count' => count($cohortAssignments),
                'active_cohort_count' => count($activeCohorts),
                'last_active_at' => $enrolment['last_active_at'] ?? null,
            ];
        });

        return [
            'total_students' => $students->count(),
            'students' => $rows->values()->toArray(),
        ];
    }

    public function getType(): string
    {
        return 'student_table';
    }

    /**
     * Fetch all enrolment records from the Enrolment Service.
     *
     * Calls: GET {enrolment_service}/api/school/enrolments
     * Expected response: { "data": [ { "student_id": 3, "cohort_assignments": [
     *   { "cohort_id": 1, "status": "active" }, ... ], "last_active_at": "..." }, ... ] }
     *
     * On failure: returns an empty array. The calling code handles this by
     * showing all students with 'unknown' enrolment status rather than hiding
     * the entire student table.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchEnrolmentData(): array
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout(5)
                ->get(config('services.enrolment.url') . '/api/school/enrolments');

            if ($response->successful()) {
                return $response->json('data', []);
            }
        } catch (\Exception $e) {
            // Degraded — return empty so students show "unknown" status
        }

        return [];
    }
}
