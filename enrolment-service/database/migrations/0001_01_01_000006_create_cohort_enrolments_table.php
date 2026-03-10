<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cohort_enrolments')) {
            return;
        }

        Schema::create('cohort_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cohort_id')->constrained('cohorts');
            $table->foreignId('student_id')->constrained('users');
            $table->string('status', 20)->default('enrolled');
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('removed_at')->nullable();
            $table->unique(['cohort_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cohort_enrolments');
    }
};
