<?php

declare(strict_types=1);

/**
 * Experience model — A curriculum package built by a teacher for a school.
 *
 * This is a reference/lookup model in the Enrolment Service. The experiences table
 * is owned by the Experience Service (port 8002), but the Enrolment Service has
 * read access because it runs against the same shared PostgreSQL database.
 *
 * An Experience is the "class" (template); a Cohort is the "object" (live instance).
 * One Experience can have many Cohorts, each with different teachers, date ranges,
 * and enrolled students.
 *
 * Entity relationship:
 *   Experience 1--* Cohort (a cohort always belongs to one experience)
 *
 * The Enrolment Service uses this model primarily for display purposes — showing
 * experience names alongside cohort and enrolment data in the overview, detail,
 * and export views.
 *
 * @see \App\Models\Cohort  The live running instance of an experience
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A curriculum package (template) built by a teacher for a school.
 *
 * Read-only reference model in the Enrolment Service -- the experiences table
 * is owned by the Experience Service (port 8002). Used here to display
 * experience names alongside cohort and enrolment data. One Experience can
 * have many Cohorts, each a separate live offering of the same curriculum.
 */
class Experience extends Model
{
    protected $fillable = ['school_id', 'name', 'description', 'status', 'created_by'];

    /**
     * All cohorts created from this experience.
     *
     * Each cohort is a separate offering of the same curriculum package,
     * potentially with different teachers, date ranges, and student groups.
     */
    public function cohorts(): HasMany
    {
        return $this->hasMany(Cohort::class);
    }
}
