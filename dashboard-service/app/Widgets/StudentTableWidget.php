<?php

declare(strict_types=1);

namespace App\Widgets;

use App\Contracts\DashboardWidget;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Student table widget for the School Admin Dashboard.
 *
 * Returns a formatted list of students belonging to the authenticated user's
 * school, enriched with enrolment status data from the Enrolment Service.
 * Each row contains the student's name, email, enrolment status, cohort count,
 * and last activity timestamp. Degrades gracefully when the Enrolment Service
 * is unavailable by showing students with an "unknown" enrolment status.
 */
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
        $students = User::where('school_id', $this->schoolId)
            ->where('role', 'student')
            ->get();

        $enrolmentData = $this->fetchEnrolmentData();

        // Index enrolment records by student_id for O(1) lookups
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

            // Determine status: enrolled if in any active cohort, inactive if
            // only in completed/not_started cohorts, unassigned if not in any
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
