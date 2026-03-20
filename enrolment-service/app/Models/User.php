<?php

declare(strict_types=1);

/**
 * User model — Represents a person in the Hatchloom platform.
 *
 * This is a reference/lookup model in the Enrolment Service. The users table is
 * seeded with mock data and is NOT owned by the Enrolment Service. Real user
 * management is handled by Team Quebec's Auth service.
 *
 * Users have three roles relevant to the Enrolment Service:
 *  - school_admin: Can manage cohorts and enrolments for their school
 *  - school_teacher: Can manage cohorts and enrolments; assigned as cohort teachers
 *  - student: Can be enrolled into cohorts; cannot access admin endpoints
 *
 * Extends Laravel's Authenticatable base class (not the plain Model) because
 * MockAuthMiddleware uses Auth::login() to set the authenticated user, which
 * requires the Authenticatable interface.
 *
 * @see \App\Http\Middleware\MockAuthMiddleware  Where Auth::login() is called
 * @see \App\Models\Scopes\SchoolScope           Uses school_id from the authenticated user
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A person in the Hatchloom platform (admin, teacher, student, or parent).
 *
 * Read-only reference model -- user management is owned by Team Quebec's Auth
 * service. Extends Authenticatable (not plain Model) because MockAuthMiddleware
 * uses Auth::login() which requires the Authenticatable contract.
 *
 * Roles relevant to the Enrolment Service:
 *  - school_admin:   manages cohorts and enrolments for their school
 *  - school_teacher: manages cohorts; can be assigned as a cohort teacher
 *  - student:        can be enrolled into cohorts; read-only access to own data
 *  - parent:         read-only access to their child's enrolment data
 *
 * @see \App\Http\Middleware\MockAuthMiddleware  Where Auth::login() is called
 * @see \App\Models\Scopes\SchoolScope          Uses school_id for tenant isolation
 */
class User extends Authenticatable
{
    /**
     * Mass-assignable attributes.
     *
     * Includes 'grade' which is seeded for students but not yet used in filtering
     * (grade-based filtering for the enrolment overview is planned).
     */
    protected $fillable = ['name', 'email', 'password', 'role', 'school_id', 'grade', 'parent_of'];

    /**
     * Attributes hidden from JSON serialization.
     *
     * Prevents the password hash from appearing in API responses if the model
     * is ever accidentally serialized directly.
     */
    protected $hidden = ['password'];

    /**
     * The school this user belongs to.
     *
     * Every user in Hatchloom is associated with exactly one school. This
     * relationship is used by MockAuthMiddleware and EnrolmentController to
     * verify students belong to the admin's school before enrolment.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
