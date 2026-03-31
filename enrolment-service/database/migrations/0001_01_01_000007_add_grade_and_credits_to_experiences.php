<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            $table->unsignedSmallInteger('grade')->nullable()->after('status');
            $table->unsignedSmallInteger('total_credits')->nullable()->after('grade');
        });
    }

    public function down(): void
    {
        Schema::table('experiences', function (Blueprint $table) {
            $table->dropColumn(['grade', 'total_credits']);
        });
    }
};
