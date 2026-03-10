<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An Experience is a curated collection of courses assembled by a teacher,
 * belonging to a single school.
 *
 * Think of it as a "curriculum package" — the template from which Cohorts
 * (live running instances with enrolled students) are created. Uses soft
 * deletes so archived Experiences remain available for audit and reporting.
 * Automatically scoped to the authenticated user's school via SchoolScope.
 */
class Experience extends Model
{
    use SoftDeletes;

    protected $fillable = ['school_id', 'name', 'description', 'status', 'created_by'];

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(ExperienceCourse::class)->orderBy('sequence');
    }
}
