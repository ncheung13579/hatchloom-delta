<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cohorts')) {
            return;
        }

        Schema::create('cohorts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_id')->constrained('experiences');
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name', 255);
            $table->string('status', 20)->default('not_started');
            $table->foreignId('teacher_id')->nullable()->constrained('users');
            $table->integer('capacity')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cohorts');
    }
};
