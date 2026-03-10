<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cohort;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class CohortService
{
    public function listCohorts(?int $experienceId = null, ?string $status = null): Collection
    {
        $query = Cohort::query()->with(['experience', 'teacher']);

        if ($experienceId) {
            $query->where('experience_id', $experienceId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    public function getCohort(int $id): ?Cohort
    {
        return Cohort::with(['experience', 'teacher', 'enrolments'])->find($id);
    }

    public function createCohort(array $data): Cohort
    {
        return Cohort::create([
            'experience_id' => $data['experience_id'],
            'school_id' => Auth::user()->school_id,
            'name' => $data['name'],
            'status' => 'not_started',
            'teacher_id' => $data['teacher_id'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
        ]);
    }

    public function updateCohort(Cohort $cohort, array $data): Cohort
    {
        $cohort->update(array_filter([
            'name' => $data['name'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'teacher_id' => $data['teacher_id'] ?? null,
        ], fn($v) => $v !== null));

        return $cohort->fresh();
    }

    public function activateCohort(Cohort $cohort): bool
    {
        return $cohort->activate();
    }

    public function completeCohort(Cohort $cohort): bool
    {
        return $cohort->complete();
    }
}
