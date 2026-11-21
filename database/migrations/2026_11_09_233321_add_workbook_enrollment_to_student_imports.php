<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_imports', function (Blueprint $table): void {
            $table->unsignedBigInteger('section_id')->nullable()->change();
            $table->foreignId('school_year_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('education_level_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('student_import_rows', function (Blueprint $table): void {
            $table->text('access_payload')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('student_imports')->whereNull('section_id')->exists()) {
            throw new LogicException('Las importaciones por año y nivel requieren el esquema actual.');
        }
        Schema::table('student_import_rows', function (Blueprint $table): void {
            $table->dropColumn('access_payload');
        });
        Schema::table('student_imports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('school_year_id');
            $table->dropConstrainedForeignId('education_level_id');
            $table->unsignedBigInteger('section_id')->nullable(false)->change();
        });
    }
};
