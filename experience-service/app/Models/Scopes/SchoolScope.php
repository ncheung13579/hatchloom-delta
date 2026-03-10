<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope that enforces tenant isolation by automatically adding
 * a WHERE school_id = ? clause to every query on models that use it.
 *
 * This is a hard security requirement — it ensures that no school can
 * ever see or modify another school's data, even if a query forgets
 * an explicit filter. Applied via the Decorator pattern in each
 * model's booted() method.
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
