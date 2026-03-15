<?php

/**
 * Experience model — a curated collection of courses (a "curriculum package").
 *
 * Database table: `experiences`
 * Owned by: Experience Service (this service runs migrations for this table)
 *
 * Architecture role:
 *   The Experience is the primary entity of the Experience Service. It represents a
 *   reusable curriculum template assembled by a teacher. Cohorts (live running instances
 *   with enrolled students) are created from an Experience, but cohorts live in the
 *   Enrolment Service — not here. This separation means the Experience Service only
 *   knows about the template; the Enrolment Service tracks who is enrolled in what.
 *
 * Multi-tenancy (Decorator pattern):
 *   The SchoolScope global scope is registered in booted(). This means EVERY Eloquent
 *   query on this model (find, where, paginate, etc.) automatically gets a
 *   WHERE school_id = {authenticated user's school_id} clause appended. This is the
 *   primary mechanism for preventing cross-school data leakage. You do not need to
 *   manually filter by school_id — the scope does it for you.
 *
 * Soft deletes:
 *   Uses Laravel's SoftDeletes trait. When an Experience is "deleted," the deleted_at
 *   column is set instead of removing the row. This preserves data for audit trails.
 *   The deleteExperience() method in ExperienceService also sets status='archived'
 *   before soft-deleting, providing a double signal for reporting queries.
 *
 * Status lifecycle: 'active' -> 'archived' (one-directional, set on delete)
 *
 * Relationships:
 *   - school(): BelongsTo -> School (the owning institution)
 *   - creator(): BelongsTo -> User (the teacher who created this experience; FK is created_by)
 *   - courses(): HasMany -> ExperienceCourse (pivot records linking to upstream course IDs)
 */

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Experience extends Model
{
    use SoftDeletes;

    /**
     * Mass-assignable fields.
     *   - school_id: FK to schools table (set automatically from Auth::user()->school_id)
     *   - name: Human-readable experience title (e.g., "Grade 10 Business Basics")
     *   - description: Longer explanation of what this experience covers
     *   - status: 'active' or 'archived' — controls visibility in listings
     *   - created_by: FK to users table — the teacher who created this experience
     */
    protected $fillable = ['school_id', 'name', 'description', 'status', 'created_by'];

    /**
     * Register the SchoolScope global scope for automatic tenant isolation.
     *
     * This is called once when the model class is first used. After this, every query
     * on Experience will include WHERE experiences.school_id = {current user's school_id}.
     * There is no way to accidentally query another school's experiences unless you
     * explicitly call withoutGlobalScope(SchoolScope::class) — which should never be
     * done in application code.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
    }

    /** The school that owns this experience. */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The teacher who created this experience.
     * Uses a custom FK column name ('created_by') instead of the default 'user_id'.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The courses included in this experience, ordered by their sequence number.
     *
     * Each ExperienceCourse record holds a course_id (referencing Team Papa's Course Service)
     * and a sequence number. The orderBy('sequence') ensures courses always come back in
     * the order the teacher arranged them, not insertion order.
     */
    public function courses(): HasMany
    {
        return $this->hasMany(ExperienceCourse::class)->orderBy('sequence');
    }
}
