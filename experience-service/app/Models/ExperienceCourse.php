<?php

/**
 * ExperienceCourse model — pivot linking an Experience to a course in the upstream catalogue.
 *
 * Database table: `experience_courses`
 * Owned by: Experience Service (this service runs migrations for this table)
 *
 * Architecture role:
 *   This is an explicit pivot model (not a simple many-to-many pivot table) because we
 *   need to store a `sequence` attribute and we want to use Eloquent's HasMany relationship
 *   instead of BelongsToMany. This gives us more control over ordering and querying.
 *
 *   The course_id column references a course in Team Papa's Course Service — it's NOT a
 *   foreign key to a local table. Course names and block data are resolved at runtime via
 *   the CourseDataProviderInterface (Strategy pattern). This loose coupling means the
 *   Experience Service can function even if Team Papa's service is unavailable.
 *
 * Why timestamps are disabled:
 *   When an Experience's course list is updated, the ExperienceService does a full
 *   replacement: delete all existing ExperienceCourse rows, then insert new ones.
 *   Since rows are never individually updated, created_at/updated_at would be meaningless.
 *
 * Relationships:
 *   - experience(): BelongsTo -> Experience (the parent experience this course belongs to)
 *
 * Fields:
 *   - experience_id: FK to the experiences table
 *   - course_id: External reference to Team Papa's course catalogue (NOT a local FK)
 *   - sequence: 1-based ordering of courses within the experience
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperienceCourse extends Model
{
    /** Timestamps disabled — rows are bulk-replaced, not individually updated. */
    public $timestamps = false;

    protected $fillable = ['experience_id', 'course_id', 'sequence'];

    /** The parent Experience this course association belongs to. */
    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }
}
