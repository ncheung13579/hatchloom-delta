<?php

declare(strict_types=1);

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
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::check()) {
            $builder->where($model->getTable() . '.school_id', Auth::user()->school_id);
        }
    }
}
