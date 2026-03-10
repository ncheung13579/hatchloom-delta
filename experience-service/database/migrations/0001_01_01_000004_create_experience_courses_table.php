<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('experience_courses')) {
            return;
        }

        Schema::create('experience_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experience_id')->constrained('experiences')->onDelete('cascade');
            $table->unsignedBigInteger('course_id');
            $table->integer('sequence')->default(1);
            $table->unique(['experience_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_courses');
    }
};
