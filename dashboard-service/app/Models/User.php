<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'role', 'school_id'];

    protected $hidden = ['password'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
