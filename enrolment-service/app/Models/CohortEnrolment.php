<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CohortEnrolment extends Model
{
    public $timestamps = false;

    protected $fillable = ['cohort_id', 'student_id', 'status', 'enrolled_at', 'removed_at'];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function remove(): void
    {
        $this->status = 'removed';
        $this->removed_at = now();
        $this->save();
    }
}
