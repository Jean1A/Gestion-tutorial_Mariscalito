<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('dni', 8)->unique();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('must_change_password')->default(true);
            $table->unsignedBigInteger('session_version')->default(1);
            $table->softDeletes();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('label', 60);
            $table->timestamps();
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->primary(['role_id', 'user_id']);
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('label', 120);
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('education_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('education_level_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->unique(['education_level_id', 'name']);
            $table->timestamps();
        });
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_id')->constrained()->restrictOnDelete();
            $table->string('name', 30);
            $table->boolean('is_active')->default(true);
            $table->unique(['school_year_id', 'grade_id', 'name']);
            $table->unique(['id', 'school_year_id'], 'sections_year_identity');
            $table->timestamps();
        });
        Schema::create('academic_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unique(['school_year_id', 'name']);
            $table->timestamps();
        });
        Schema::create('school_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->boolean('is_instructional');
            $table->string('description', 160)->nullable();
            $table->unique(['school_year_id', 'date']);
            $table->timestamps();
        });
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('dni', 8)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 140)->index();
            $table->date('birth_date')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['active', 'withdrawn', 'transferred'])->default('active');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unique(['student_id', 'school_year_id']);
            $table->index(['section_id', 'status']);
            $table->foreign(['section_id', 'school_year_id'])->references(['id', 'school_year_id'])->on('sections')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('guardian_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('relationship', 60);
            $table->boolean('is_active')->default(true);
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->unique(['user_id', 'student_id']);
            $table->timestamps();
        });
        Schema::create('tutor_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unique(['user_id', 'section_id']);
            $table->timestamps();
        });
        Schema::create('assistant_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unique(['user_id', 'grade_id', 'school_year_id'], 'assistant_assignment_unique');
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 100)->index();
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['subject_type', 'subject_id']);
        });
        Schema::create('guardian_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('policy_version', 50);
            $table->timestamp('accepted_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['guardian_consents', 'audit_logs', 'settings', 'assistant_assignments', 'tutor_assignments', 'guardian_student', 'enrollments', 'students', 'school_days', 'academic_periods', 'sections', 'grades', 'education_levels', 'school_years', 'permission_role', 'permissions', 'role_user', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['dni', 'phone', 'is_active', 'must_change_password', 'session_version']);
        });
    }
};
