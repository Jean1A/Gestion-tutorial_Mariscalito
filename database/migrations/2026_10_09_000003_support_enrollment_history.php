<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', fn (Blueprint $table) => $table->index(['student_id', 'school_year_id'], 'enrollment_student_year_index'));
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'school_year_id']);
            $table->unique(['student_id', 'school_year_id', 'starts_on'], 'enrollment_start_unique');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', fn (Blueprint $table) => $table->dropUnique('enrollment_start_unique'));
    }
};
