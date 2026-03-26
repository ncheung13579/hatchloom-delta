<?php

/**
 * Replace the parent_of FK column with a parent_student_links join table.
 *
 * The canonical Hatchloom data model (Karl's Role B workpack) defines parent-child
 * relationships as many-to-many via a `parent_student_links` table:
 *   - One parent can have multiple children (students)
 *   - One student can have multiple parents/guardians
 *
 * The previous implementation used a single `parent_of` FK on the users table,
 * which only supported one-to-one relationships.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_student_links', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('student_id');
            $table->primary(['parent_id', 'student_id']);
            $table->index('parent_id');
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_student_links');
    }
};
