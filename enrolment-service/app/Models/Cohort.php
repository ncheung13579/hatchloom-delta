<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A live running instance of an Experience, scoped to a single school.
 *
 * While an Experience is the template (the "class"), a Cohort is a concrete
 * offering (the "object") with a teacher, date range, capacity, and enrolled
 * students. Follows a one-directional status lifecycle:
 *   not_started -> active -> completed
 *
 * Automatically filtered by SchoolScope to enforce tenant isolation.
 */
class Cohort extends Model
{
    protected $fillable = [
        'experience_id', 'school_id', 'name', 'status',
        'teacher_id', 'capacity', 'start_date', 'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'capacity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(CohortEnrolment::class);
    }

    public function activeEnrolments(): HasMany
    {
        return $this->hasMany(CohortEnrolment::class)->where('status', 'enrolled');
    }

    /**
     * Transition to active. Guard: only allowed from not_started.
     * Returns false without saving if the current state is anything else.
     */
    public function activate(): bool
    {
        if ($this->status !== 'not_started') {
            return false;
        }
        $this->status = 'active';
        return $this->save();
    }

    /**
     * Transition to completed (terminal state). Guard: only allowed from active.
     * Returns false without saving if the cohort is not currently active.
     */
    public function complete(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        $this->status = 'completed';
        return $this->save();
    }
}
