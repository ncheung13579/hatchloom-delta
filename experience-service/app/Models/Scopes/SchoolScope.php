<?php

/**
 * SchoolScope — Global scope that enforces multi-tenant data isolation.
 *
 * Architecture role:
 *   This is the MOST CRITICAL security mechanism in the Experience Service. It
 *   implements the Decorator pattern (SDD Section 6.6) by transparently wrapping
 *   every Eloquent query with a WHERE school_id = ? clause. This prevents any
 *   school from ever seeing or modifying another school's data, regardless of
 *   what the controller or service layer does.
 *
 * How it works:
 *   1. A model registers this scope in its booted() method:
 *        static::addGlobalScope(new SchoolScope());
 *   2. Every time Eloquent builds a query for that model (SELECT, UPDATE, DELETE),
 *      Laravel calls apply() on all registered global scopes.
 *   3. apply() reads Auth::user()->school_id (set by MockAuthMiddleware earlier in
 *      the request lifecycle) and appends WHERE {table}.school_id = {value}.
 *   4. The table prefix ({table}.school_id) prevents ambiguity in JOIN queries.
 *
 * Why Auth::check() guard?
 *   During artisan commands (migrations, seeders) and some test scenarios, there
 *   is no authenticated user. The Auth::check() guard prevents a crash in those
 *   contexts. In production HTTP requests, Auth::check() should always be true
 *   because MockAuthMiddleware runs before any model query.
 *
 * Which models use this scope?
 *   Currently: Experience. School and User do NOT use it because they are the
 *   source of school_id, not consumers of it.
 *
 * @see \App\Models\Experience::booted()          Where this scope is registered
 * @see \App\Http\Middleware\MockAuthMiddleware    Sets Auth::user() before queries run
 */

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class SchoolScope implements Scope
{
    /**
     * Apply the school isolation filter to every query on this model.
     *
     * @param Builder $builder  The Eloquent query builder being constructed
     * @param Model   $model    The model instance (used to get the table name for column prefixing)
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::check()) {
            // Prefix with table name to avoid "ambiguous column" errors in JOINs.
            $builder->where($model->getTable() . '.school_id', Auth::user()->school_id);
        }
    }
}
