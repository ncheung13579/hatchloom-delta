<?php

declare(strict_types=1);

/**
 * School model — Represents an educational institution using Hatchloom.
 *
 * This is a reference/lookup model in the Enrolment Service. The schools table is
 * seeded with mock data and is NOT owned by the Enrolment Service (it is shared
 * infrastructure). All three microservices (Dashboard, Experience, Enrolment) seed
 * the same schools table so they can enforce tenant isolation.
 *
 * The School model is the root of the multi-tenancy hierarchy:
 *   School 1--* User (admins, teachers, students)
 *   School 1--* Experience (curriculum packages)
 *   School 1--* Cohort (live running instances of experiences)
 *
 * The SchoolScope global scope on Cohort uses the authenticated user's school_id
 * to ensure all queries are automatically filtered to the correct tenant.
 *
 * @see \App\Models\Scopes\SchoolScope  Automatic tenant filtering
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents an educational institution (tenant) in the Hatchloom platform.
 *
 * Read-only reference model -- the schools table is shared infrastructure
 * seeded by all three microservices. The School is the root of the multi-
 * tenancy hierarchy: all Users, Experiences, and Cohorts belong to a School.
 * SchoolScope on the Cohort model uses the authenticated user's school_id
 * to enforce automatic tenant isolation at the query level.
 *
 * @see \App\Models\Scopes\SchoolScope  Automatic tenant filtering
 */
class School extends Model
{
    /**
     * Mass-assignable attributes.
     *
     * Using $fillable (not $guarded = []) is a project-wide security convention
     * that prevents accidental mass-assignment of sensitive fields.
     */
    protected $fillable = ['name', 'code', 'is_active'];

    /**
     * Attribute type casts.
     *
     * Ensures is_active is always a boolean in PHP, even though PostgreSQL
     * stores it as a smallint or boolean column type.
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * All users (admins, teachers, students) belonging to this school.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
