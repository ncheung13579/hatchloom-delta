<?php

/**
 * User model — represents any person with a Hatchloom account.
 *
 * Database table: `users`
 *
 * Architecture role:
 *   The User model is reference data in the Experience Service — users are seeded
 *   (not created via this service's API). The MockAuthMiddleware looks up User records
 *   to populate Auth::user(), which in turn drives:
 *     - SchoolScope: reads user->school_id to filter all queries by school
 *     - Experience.created_by: records which user created an experience
 *     - Audit logging: records user_id and role on mutating requests
 *
 *   Extends Laravel's Authenticatable base class (not the plain Model) because
 *   Auth::login($user) requires the user model to implement the Authenticatable
 *   contract (provides getAuthIdentifier, etc.).
 *
 * Relationships:
 *   - school(): BelongsTo -> School (the institution this user belongs to)
 *
 * Roles (stored as string in the `role` column):
 *   - 'school_admin': Can manage all experiences and view all data for their school
 *   - 'school_teacher': Can create/edit experiences assigned to them
 *   - 'student': NOT permitted to access the Experience Service (blocked by MockAuthMiddleware)
 *
 * Note: Like School, this model does NOT have a SchoolScope — it is the source of
 * school_id, not a consumer of it.
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /**
     * Mass-assignable fields.
     *   - name: Full display name
     *   - email: Login email address
     *   - password: Hashed password (not used in mock auth, but present for schema completeness)
     *   - role: One of 'school_admin', 'school_teacher', 'student'
     *   - school_id: FK to the schools table — determines tenant isolation
     */
    protected $fillable = ['name', 'email', 'password', 'role', 'school_id', 'grade', 'parent_of'];

    /** Fields excluded from JSON serialization to prevent accidental password exposure. */
    protected $hidden = ['password'];

    /** The school this user belongs to. Used by SchoolScope to derive tenant context. */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
