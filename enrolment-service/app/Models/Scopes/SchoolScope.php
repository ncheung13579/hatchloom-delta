<?php

declare(strict_types=1);

/**
 * SchoolScope — Global Eloquent scope for automatic multi-tenant isolation.
 *
 * Implements the Decorator pattern (SDD Section 6.6) applied to Laravel's
 * Eloquent query builder. When attached to a model (via addGlobalScope in the
 * model's booted() method), it transparently appends a WHERE clause that filters
 * all queries to the authenticated user's school.
 *
 * WHY THIS EXISTS (multi-tenancy security):
 * Hatchloom is a multi-tenant platform where multiple schools share the same
 * database. Without this scope, a school admin could potentially see or modify
 * data belonging to another school — a critical security violation. By applying
 * this scope globally, we enforce tenant isolation at the query level rather than
 * relying on every controller and service to remember to filter by school_id.
 *
 * HOW IT WORKS:
 *  1. A model (e.g., Cohort) registers this scope in its booted() method
 *  2. Every time an Eloquent query is built for that model, Laravel calls apply()
 *  3. apply() reads the authenticated user's school_id from Auth::user()
 *  4. It appends WHERE {table}.school_id = {user's school_id} to the query
 *  5. The table prefix ({table}.school_id) prevents ambiguity in JOIN queries
 *
 * IMPORTANT: The scope only applies when a user is authenticated (Auth::check()).
 * During seeding, migrations, and tests that run without authentication, the scope
 * is silently skipped. Some queries in EnrolmentService use withoutGlobalScopes()
 * to bypass this when they need cross-school data with manual school_id filtering.
 *
 * Currently applied to: Cohort model
 * Not applied to: School, User, Experience, CohortEnrolment (these are either
 *   reference data or are filtered through their relationship to Cohort)
 *
 * @see \App\Models\Cohort::booted()  Where this scope is registered
 */

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope that enforces automatic tenant isolation by school.
 *
 * Applied to any model with a school_id column (e.g., Cohort). Automatically
 * appends `WHERE school_id = ?` using the authenticated user's school_id,
 * ensuring that queries never leak data across school boundaries. This is the
 * Decorator pattern applied to Eloquent's query builder.
 */
class SchoolScope implements Scope
{
    /**
     * Apply the school isolation constraint to the Eloquent query builder.
     *
     * @param Builder $builder The query builder being constructed
     * @param Model   $model   The model instance (used to get the table name for column prefixing)
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Only apply when a user is authenticated. During seeding, migrations,
        // and unauthenticated contexts, the scope is a no-op.
        if (Auth::check()) {
            // Prefix with table name to prevent "ambiguous column" errors when
            // this model is joined with other tables that also have school_id.
            $builder->where($model->getTable() . '.school_id', Auth::user()->school_id);
        }
    }
}
