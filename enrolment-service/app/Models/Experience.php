<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Experience extends Model
{
    protected $fillable = ['school_id', 'name', 'description', 'status', 'created_by'];

    public function cohorts(): HasMany
    {
        return $this->hasMany(Cohort::class);
    }
}
