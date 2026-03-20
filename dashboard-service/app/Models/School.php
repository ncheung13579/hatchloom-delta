<?php

/**
 * School Model — Reference data for schools participating in Hatchloom.
 *
 * Architecture role:
 *   The schools table is shared reference data — it is seeded identically
 *   across all three Delta microservices (Dashboard, Experience, Enrolment).
 *   The Dashboard Service reads from this table but never creates, updates,
 *   or deletes school records. Ownership of the schools table lives with
 *   the platform's core admin layer (outside Team Delta's scope).
 *
 * Multi-tenant isolation:
 *   School is the root of the multi-tenant hierarchy. Every query for
 *   experiences, cohorts, enrolments, and users MUST be scoped by school_id.
 *   The authenticated user's school_id (from User->school_id) is the tenant
 *   boundary — no admin should ever see data from another school.
 *
 * Relationships:
 *   School 1--* User (admins, teachers, students all belong to one school)
 *
 * Seeded data (development):
 *   ID 1: "Hatchloom Demo School" (code: DEMO, is_active: true)
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    /**
     * Mass-assignable attributes. Using $fillable (not $guarded = []) per
     * our coding standards to be explicit about what can be set.
     */
    protected $fillable = ['name', 'code', 'is_active'];

    /**
     * Attribute casting ensures is_active is always a boolean in PHP,
     * even though PostgreSQL stores it as a smallint/boolean column.
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * All users (admins, teachers, students) belonging to this school.
     * Used by DashboardService to query students scoped to the current school.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
