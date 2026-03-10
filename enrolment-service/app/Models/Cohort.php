<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\SchoolScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cohort extends Model
{
    protected $fillable = [
        'experience_id', 'school_id', 'name', 'status',
        'teacher_id', 'capacity', 'start_date', 'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'capacity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new SchoolScope());
    }

    public function experience(): BelongsTo
    {
        return $this->belongsTo(Experience::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(CohortEnrolment::class);
    }

    public function activeEnrolments(): HasMany
    {
        return $this->hasMany(CohortEnrolment::class)->where('status', 'enrolled');
    }

    public function activate(): bool
    {
        if ($this->status !== 'not_started') {
            return false;
        }
        $this->status = 'active';
        return $this->save();
    }

    public function complete(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        $this->status = 'completed';
        return $this->save();
    }
}
