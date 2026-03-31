<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

/**
 * Provides CSV value sanitization for controllers that export CSV files.
 *
 * Extracted from ExperienceScreenController to eliminate duplication with
 * EnrolmentController (enrolment-service). Both controllers use the
 * same sanitization logic for CSV exports.
 */
trait SanitizesCsvOutput
{
    private static function sanitizeCsvValue(?string $value): string
    {
        return $value ?? '';
    }
}
