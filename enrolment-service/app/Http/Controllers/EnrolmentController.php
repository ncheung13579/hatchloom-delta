<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cohort;
use App\Models\CohortEnrolment;
use App\Models\User;
use App\Services\EnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnrolmentController extends Controller
{
    public function __construct(
        private readonly EnrolmentService $enrolmentService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 15);

        $overview = $this->enrolmentService->getEnrolmentOverview($search, $perPage);

        return response()->json([
            'data' => $overview->items(),
            'meta' => [
                'current_page' => $overview->currentPage(),
                'last_page' => $overview->lastPage(),
                'per_page' => $overview->perPage(),
                'total' => $overview->total(),
            ],
        ]);
    }

    public function enrol(Request $request, int $cohortId): JsonResponse
    {
        $cohort = Cohort::find($cohortId);

        if (!$cohort) {
            return response()->json([
                'error' => true,
                'message' => 'Cohort not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $validated = $request->validate([
            'student_id' => 'required|integer',
        ]);

        $studentId = $validated['student_id'];

        // Verify student belongs to same school
        $student = User::where('id', $studentId)
            ->where('school_id', Auth::user()->school_id)
            ->where('role', 'student')
            ->first();

        if (!$student) {
            return response()->json([
                'error' => true,
                'message' => 'Student not found or not in your school',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        // Check cohort is active
        if ($cohort->status !== 'active') {
            return response()->json([
                'error' => true,
                'message' => 'Cohort is not active',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        // Check capacity
        if ($cohort->capacity) {
            $currentCount = $cohort->activeEnrolments()->count();
            if ($currentCount >= $cohort->capacity) {
                return response()->json([
                    'error' => true,
                    'message' => 'Cohort is at full capacity',
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }
        }

        // Check for duplicate
        $existing = CohortEnrolment::where('cohort_id', $cohort->id)
            ->where('student_id', $studentId)
            ->first();

        if ($existing) {
            return response()->json([
                'error' => true,
                'message' => 'Student is already enrolled in this cohort',
                'code' => 'DUPLICATE_ENROLMENT',
            ], 422);
        }

        $enrolment = $this->enrolmentService->enrolStudent($cohort, $studentId);

        return response()->json([
            'id' => $enrolment->id,
            'cohort_id' => $enrolment->cohort_id,
            'student_id' => $enrolment->student_id,
            'status' => $enrolment->status,
            'enrolled_at' => $enrolment->enrolled_at?->toIso8601String(),
        ], 201);
    }

    public function remove(int $cohortId, int $studentId): JsonResponse
    {
        $cohort = Cohort::find($cohortId);

        if (!$cohort) {
            return response()->json([
                'error' => true,
                'message' => 'Cohort not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $enrolment = $this->enrolmentService->removeStudent($cohort, $studentId);

        if (!$enrolment) {
            return response()->json([
                'error' => true,
                'message' => 'Enrolment not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json(['message' => 'Student removed from cohort']);
    }

    public function statistics(): JsonResponse
    {
        return response()->json($this->enrolmentService->calculateStatistics());
    }

    public function export(): StreamedResponse
    {
        $rows = $this->enrolmentService->exportEnrolmentList();

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['student_name', 'student_email', 'cohort_name', 'experience_name', 'status', 'enrolled_at', 'removed_at']);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 'enrolments.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
