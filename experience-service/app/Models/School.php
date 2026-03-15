<?php

/**
 * School model — represents an educational institution using Hatchloom.
 *
 * Database table: `schools`
 *
 * Architecture role:
 *   The School is the top-level tenant entity in Hatchloom's multi-tenant architecture.
 *   Every user, experience, cohort, and enrolment belongs to exactly one school. The
 *   SchoolScope global scope on other models uses the authenticated user's school_id
 *   to enforce data isolation between schools.
 *
 *   In the Experience Service, the School model is primarily reference data — it's seeded
 *   (not created via API) and used for the foreign key relationship on Experiences and
 *   Users. The Experience Service does not own the `schools` table; it's shared across
 *   all three microservices connecting to the same PostgreSQL database.
 *
 * Relationships:
 *   - users(): HasMany -> User (admins, teachers, students at this school)
 *   - experiences(): HasMany -> Experience (curriculum packages created for this school)
 *
 * Note: This model does NOT have a SchoolScope because it IS the school. Filtering
 * schools by school_id would be circular and meaningless.
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    /**
     * Mass-assignable fields.
     *
     * Laravel requires either $fillable (whitelist) or $guarded (blacklist) to protect
     * against mass-assignment vulnerabilities. We use $fillable per project convention.
     *   - name: Display name of the school (e.g., "Riverside High School")
     *   - code: Short unique identifier for the school (e.g., "RHS")
     *   - is_active: Whether the school's Hatchloom subscription is currently active
     */
    protected $fillable = ['name', 'code', 'is_active'];

    /**
     * Attribute type casting — ensures is_active is always a PHP boolean,
     * not the raw integer (0/1) stored in PostgreSQL.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** All users (admins, teachers, students) belonging to this school. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** All experiences (curriculum packages) created for this school. */
    public function experiences(): HasMany
    {
        return $this->hasMany(Experience::class);
    }
}
