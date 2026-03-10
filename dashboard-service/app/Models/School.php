<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reference model for schools participating in Hatchloom.
 *
 * The schools table is seeded as shared reference data — it is not owned by
 * the Dashboard Service. All data queries are scoped by school_id to enforce
 * multi-tenant isolation.
 */
class School extends Model
{
    protected $fillable = ['name', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
