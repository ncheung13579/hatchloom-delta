<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\CohortState;
use App\Models\Scopes\SchoolScope;
use App\States\ActiveState;
use App\States\CompletedState;
use App\States\NotStartedState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A live running instance of an Experience, scoped to a single school.
 *
 * While an Experience is the template (the "class"), a Cohort is a concrete
 * offering (the "object") with a teacher, date range, capacity, and enrolled
 * students. Follows a one-directional status lifecycle managed by the State
 * pattern:
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

    // ── State pattern ──────────────────────────────────────────

    /**
     * Map status strings to their corresponding state objects.
     */
    private static array $stateMap = [
        'not_started' => NotStartedState::class,
        'active' => ActiveState::class,
        'completed' => CompletedState::class,
    ];

    /**
     * Resolve the current CohortState object from the status column.
     */
    public function state(): CohortState
    {
        $stateClass = self::$stateMap[$this->status] ?? NotStartedState::class;
        return new $stateClass();
    }

    /**
     * Transition to active. Delegates to the current state to check validity.
     */
    public function activate(): bool
    {
        if (! $this->state()->canActivate()) {
            return false;
        }
        $this->status = (new ActiveState())->status();
        return $this->save();
    }

    /**
     * Transition to completed (terminal state). Delegates to the current state.
     */
    public function complete(): bool
    {
        if (! $this->state()->canComplete()) {
            return false;
        }
        $this->status = (new CompletedState())->status();
        return $this->save();
    }

    // ── Relationships ──────────────────────────────────────────

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
     * Count enrolments with status 'removed' for this cohort.
     */
    public function removedCount(): int
    {
        return $this->enrolments()->where('status', 'removed')->count();
    }
}
