<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot model linking an Experience to a course in the upstream catalogue.
 *
 * Stores the course_id (referencing Team Papa's Course Service) and a
 * sequence number that defines the order in which courses appear within
 * the Experience. Timestamps are disabled since ordering is the only
 * mutable concern and is managed via full replacement on update.
 */
class ExperienceCourse extends Model
{
    public $timestamps = false;

    protected $fillable = ['experience_id', 'course_id', 'sequence'];

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }
}
