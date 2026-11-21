<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('icon', 40)->default('book');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->foreignId('section_id')->constrained()->restrictOnDelete();
            $t->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $t->text('content')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unique(['course_id', 'section_id']);
            $t->timestamps();
        });
        Schema::create('course_attendance_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $t->foreignId('school_day_id')->constrained()->restrictOnDelete();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->unique(['course_offering_id', 'school_day_id'], 'course_session_day_unique');
            $t->timestamps();
        });
        Schema::create('course_attendance_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_attendance_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $t->enum('status', ['present', 'late', 'justified_absence', 'unjustified_absence']);
            $t->time('arrival_time')->nullable();
            $t->unique(['course_attendance_session_id', 'enrollment_id'], 'course_record_enrollment_unique');
            $t->index(['enrollment_id', 'status']);
            $t->timestamps();
        });
        Schema::create('course_attendance_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $t->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $t->string('type', 20);
            $t->decimal('threshold', 6, 2);
            $t->decimal('observed_value', 6, 2);
            $t->boolean('is_active')->default(true)->index();
            $t->unique(['course_offering_id', 'enrollment_id', 'type', 'threshold'], 'course_alert_threshold_unique');
            $t->timestamps();
        });
        Schema::create('absence_justifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_attendance_record_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('reason', 30);
            $t->text('description');
            $t->string('path')->nullable();
            $t->string('mime', 60)->nullable();
            $t->string('status', 20)->default('pending')->index();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('course_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $t->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $t->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $t->enum('achievement', ['AD', 'A', 'B', 'C']);
            $t->boolean('is_published')->default(false);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->unique(['course_offering_id', 'enrollment_id', 'academic_period_id'], 'course_period_result_unique');
            $t->timestamps();
        });
        Schema::create('family_workshops', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('title', 150);
            $t->text('description');
            $t->boolean('is_published')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('workshop_lessons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_workshop_id')->constrained()->restrictOnDelete();
            $t->string('title', 150);
            $t->text('content');
            $t->unsignedInteger('position');
            $t->unique(['family_workshop_id', 'position']);
            $t->timestamps();
        });
        Schema::create('workshop_completions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workshop_lesson_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->unique(['workshop_lesson_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('workshop_surveys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_workshop_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->unsignedTinyInteger('rating');
            $t->string('comment', 1000)->nullable();
            $t->unique(['family_workshop_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('forum_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_workshop_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->text('body');
            $t->string('status', 20)->default('pending')->index();
            $t->foreignId('moderated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('moderated_at')->nullable();
            $t->timestamps();
        });
        Schema::create('family_meetings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('section_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('title', 150);
            $t->timestamp('scheduled_at');
            $t->string('location', 150);
            $t->boolean('is_published')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('meeting_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_meeting_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->boolean('attended');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->unique(['family_meeting_id', 'user_id']);
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['meeting_attendances', 'family_meetings', 'forum_posts', 'workshop_surveys', 'workshop_completions', 'workshop_lessons', 'family_workshops', 'course_results', 'absence_justifications', 'course_attendance_alerts', 'course_attendance_records', 'course_attendance_sessions', 'course_offerings', 'courses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
