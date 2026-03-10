<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Reference model for Hatchloom platform users (admins, teachers, students).
 *
 * The users table is seeded as shared reference data. The Dashboard Service
 * reads users for student drill-down and R3 reporting but does not create
 * or modify user records.
 */
class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'role', 'school_id'];

    protected $hidden = ['password'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
