<?php

/**
 * User Model — Reference data for Hatchloom platform users.
 *
 * Architecture role:
 *   The users table is shared reference data seeded identically across all
 *   three Delta microservices. The Dashboard Service reads user records for:
 *   - MockAuthMiddleware: resolving bearer tokens to authenticated users
 *   - Student drill-down: fetching a student's profile by ID
 *   - R3 reporting: querying all students in a school for PoS coverage
 *     and engagement metrics
 *
 *   The Dashboard Service never creates, updates, or deletes user records.
 *   User management is outside Team Delta's scope.
 *
 * Roles:
 *   - 'school_admin'   — Can access all dashboard endpoints (ID 1 in seed)
 *   - 'school_teacher'  — Can access all dashboard endpoints (ID 2 in seed)
 *   - 'student'         — Cannot access the dashboard; is the subject of
 *                          drill-down and reporting queries
 *
 * Extends Authenticatable (not plain Model) so that Auth::login($user) works
 * in MockAuthMiddleware. Authenticatable provides the interface methods that
 * Laravel's auth system requires (getAuthIdentifier, etc.).
 *
 * Relationships:
 *   User *--1 School (every user belongs to exactly one school)
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /**
     * Mass-assignable attributes. Using $fillable per coding standards.
     * The 'role' field is one of: school_admin, school_teacher, student.
     * The 'school_id' field links this user to their school (tenant boundary).
     */
    protected $fillable = ['name', 'email', 'password', 'role', 'school_id', 'grade', 'parent_of'];

    /**
     * Hide password from JSON serialization so it never leaks in API responses.
     */
    protected $hidden = ['password'];

    /**
     * The school this user belongs to. Used throughout the Dashboard Service
     * to enforce school-scoped queries: Auth::user()->school_id provides the
     * tenant boundary for all data access.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
