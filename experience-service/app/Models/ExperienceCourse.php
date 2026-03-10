<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperienceCourse extends Model
{
    public $timestamps = false;

    protected $fillable = ['experience_id', 'course_id', 'sequence'];

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }
}
