<?php

declare(strict_types=1);

/**
 * Cohort model — A live running instance of an Experience, scoped to a single school.
 *
 * This is one of the two tables owned by the Enrolment Service (the other is
 * cohort_enrolments). The Cohort model is central to the Enrolment Service's
 * domain and participates in three design patterns:
 *
 * 1. STATE PATTERN (SDD Section 6.3):
 *    Cohorts follow a strict one-directional lifecycle:
 *      not_started -> active -> completed
 *    Each status maps to a CohortState implementation (NotStartedState, ActiveState,
 *    CompletedState) that defines which transitions are valid. The lifecycle is
 *    one-directional because:
 *      - A not_started cohort is still being set up (no students can enrol yet)
 *      - An active cohort is running (students can be enrolled and removed)
 *      - A completed cohort is finished (frozen for reporting, no changes allowed)
 *    There is no way to go back: you cannot "un-complete" a cohort or "deactivate" it.
 *
 * 2. DECORATOR PATTERN (multi-tenancy):
 *    The SchoolScope global scope is automatically applied in booted(), adding
 *    WHERE school_id = ? to every query. This ensures a school admin can never
 *    see or modify cohorts belonging to another school.
 *
 * 3. REPOSITORY PATTERN:
 *    CohortService acts as the repository boundary — controllers never call
 *    Eloquent methods directly on Cohort (except for the EnrolmentController's
 *    Cohort::find() for guard checks).
 *
 * Table: cohorts
 * Columns: id, experience_id, school_id, name, status, teacher_id, capacity,
 *          start_date, end_date, created_at, updated_at
 *
 * @see \App\Contracts\CohortState      State pattern interface
 * @see \App\States\NotStartedState     Initial state — can activate
 * @see \App\States\ActiveState         Running state — can complete, accepts enrolments
 * @see \App\States\CompletedState      Terminal state — no transitions allowed
 * @see \App\Models\Scopes\SchoolScope  Automatic tenant isolation
 */

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

    /**
     * Attribute type casts.
     *
     * start_date and end_date are cast to Carbon date objects for consistent
     * date formatting and comparison. capacity is cast to integer because
     * PostgreSQL may return it as a string in some configurations.
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'capacity' => 'integer',
        ];
    }

    /**
     * Register the SchoolScope global scope when the model boots.
     *
     * This is the Decorator pattern applied to Eloquent's query builder: every
     * query on the Cohort model automatically gets a WHERE school_id = ? clause
     * based on the authenticated user's school. This happens transparently —
     * controllers and services do not need to add school filtering manually.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
    }

    // ── State pattern ──────────────────────────────────────────

    /**
     * Maps the status string stored in the database to the corresponding
     * CohortState class. When the Cohort model needs to check whether a
     * transition is valid, it instantiates the appropriate state object
     * and asks it (e.g., canActivate(), canComplete()).
     *
     * @var array<string, class-string<CohortState>>
     */
    private static array $stateMap = [
        'not_started' => NotStartedState::class,
        'active' => ActiveState::class,
        'completed' => CompletedState::class,
    ];

    /**
     * Resolve the current CohortState object from the status column.
     *
     * Looks up the status string in $stateMap and instantiates the
     * corresponding state class. Falls back to NotStartedState if the
     * status is somehow unrecognized (defensive programming).
     */
    public function state(): CohortState
    {
        $stateClass = self::$stateMap[$this->status] ?? NotStartedState::class;
        return new $stateClass();
    }

    /**
     * Transition to active. Delegates to the current state to check validity.
     *
     * Only succeeds if the current state is NotStartedState (which returns
     * true from canActivate()). ActiveState and CompletedState both return
     * false, preventing re-activation or activation of completed cohorts.
     *
     * @return bool True if the transition succeeded, false if it was rejected
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
     *
     * Only succeeds if the current state is ActiveState (which returns true
     * from canComplete()). NotStartedState cannot skip to completed, and
     * CompletedState cannot be completed again. Once completed, the cohort
     * is frozen — no further state transitions are possible.
     *
     * @return bool True if the transition succeeded, false if it was rejected
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

    /**
     * The experience (curriculum template) this cohort was created from.
     */
    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }

    /**
     * The teacher assigned to lead this cohort.
     *
     * Uses a custom foreign key 'teacher_id' because the default would be
     * 'user_id', which does not convey the semantic meaning of the relationship.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    /**
     * All enrolment records for this cohort (both active and removed).
     */
    public function enrolments(): HasMany
    {
        return $this->hasMany(CohortEnrolment::class);
    }

    /**
     * Only the currently enrolled (not removed) students in this cohort.
     *
     * This filtered relationship is used for student counts and capacity checks.
     * Removed students are excluded because they no longer occupy a seat.
     */
    public function activeEnrolments(): HasMany
    {
        return $this->hasMany(CohortEnrolment::class)->where('status', 'enrolled');
    }

    /**
     * Only the removed enrolments for this cohort.
     *
     * Used with withCount() to batch-load removed counts across multiple cohorts
     * in a single query, avoiding the N+1 pattern in cohort listings.
     */
    public function removedEnrolments(): HasMany
    {
        return $this->hasMany(CohortEnrolment::class)->where('status', 'removed');
    }

    /**
     * Transform the cohort into a flat array for list/show API responses.
     *
     * Moved here from CohortController to fix Feature Envy — the controller
     * was accessing 8+ properties and relationships of the Cohort model.
     * The model itself knows best how to represent its own data.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'experience_id' => $this->experience_id,
            'status' => $this->status,
            'teacher_name' => $this->teacher?->name,
            'student_count' => $this->active_enrolments_count ?? $this->activeEnrolments()->count(),
            'removed_count' => $this->removed_enrolments_count ?? $this->removedEnrolments()->count(),
            'capacity' => $this->capacity,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
        ];
    }
}
