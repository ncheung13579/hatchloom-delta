<?php

declare(strict_types=1);

/**
 * CohortEnrolment model — Links a student to a cohort with lifecycle tracking.
 *
 * This is one of the two tables owned by the Enrolment Service (the other is cohorts).
 * Each record represents a student's participation in a specific cohort.
 *
 * SOFT-DELETE APPROACH:
 * This model does NOT use Laravel's built-in SoftDeletes trait. Instead, it uses a
 * custom soft-delete pattern with a status field and removed_at timestamp:
 *  - status='enrolled', removed_at=null  -> Student is actively enrolled
 *  - status='removed', removed_at=<date> -> Student was removed (soft-deleted)
 *
 * Why not use Laravel SoftDeletes?
 *  - We need two distinct statuses visible in queries (enrolled vs removed)
 *  - The removed_at timestamp records WHEN the removal happened (for audit trail)
 *  - Both active and removed records appear in CSV exports and statistics
 *  - Re-enrolment after removal is allowed (duplicate check only looks for status='enrolled')
 *
 * Records are NEVER hard-deleted from the database. This preserves the full audit
 * trail for reporting, CSV export, and compliance requirements.
 *
 * Table: cohort_enrolments
 * Columns: id, cohort_id, student_id, status, enrolled_at, removed_at
 * Note: $timestamps = false because this table uses enrolled_at/removed_at instead
 *       of Laravel's default created_at/updated_at columns.
 *
 * @see \App\Models\Cohort              The parent cohort
 * @see \App\Services\EnrolmentService  Business logic for enrol/remove operations
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a student to a cohort with enrolment tracking.
 *
 * Uses a soft-delete pattern: removed students get status='removed' and a
 * removed_at timestamp rather than being hard-deleted. This preserves the
 * audit trail for reporting and CSV export. Records are never physically
 * deleted from the database.
 */
class CohortEnrolment extends Model
{
    /**
     * Disable Laravel's default created_at/updated_at timestamps.
     *
     * This table uses domain-specific timestamps (enrolled_at, removed_at) instead
     * of the generic created_at/updated_at that Laravel provides by default.
     */
    public $timestamps = false;

    protected $fillable = ['cohort_id', 'student_id', 'status', 'enrolled_at', 'removed_at'];

    /**
     * Cast enrolled_at and removed_at to Carbon datetime objects.
     *
     * This enables consistent date formatting (->toIso8601String(), ->format())
     * across the API responses and CSV export.
     */
    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * The cohort this enrolment belongs to.
     */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /**
     * The student who is enrolled (or was removed).
     *
     * Uses a custom foreign key 'student_id' because the default would be 'user_id'.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Soft-remove this enrolment by setting status to 'removed' and recording the timestamp.
     *
     * This method encapsulates the soft-delete logic so that callers (EnrolmentService)
     * do not need to know the internal field names. After calling remove(), the record
     * still exists in the database but is no longer counted as an active enrolment.
     */
    public function remove(): void
    {
        $this->status = 'removed';
        $this->removed_at = now();
        $this->save();
    }
}
