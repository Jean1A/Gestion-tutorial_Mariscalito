<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->insertOrIgnore(['name' => 'student', 'label' => 'Estudiante', 'created_at' => now(), 'updated_at' => now()]);

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('student_profile_id')->nullable()->unique()->constrained('students')->restrictOnDelete();
        });
        Schema::create('student_tutoring_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tutoring_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('instrument', 30);
            $table->string('phase', 12);
            $table->string('version', 20);
            $table->text('prompt')->nullable();
            $table->dateTime('opens_at');
            $table->dateTime('closes_at');
            $table->timestamps();
            $table->unique(['tutoring_session_id', 'instrument', 'phase'], 'student_activity_in_session');
        });
        Schema::create('student_research_permissions', function (Blueprint $table): void {
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->boolean('authorized');
            $table->timestamps();
            $table->primary(['school_year_id', 'student_id']);
        });
        Schema::create('student_tutoring_accesses', function (Blueprint $table): void {
            $table->foreignId('tutoring_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->dateTime('accessed_at');
            $table->primary(['tutoring_session_id', 'enrollment_id'], 'student_session_access');
            $table->index(['enrollment_id', 'accessed_at']);
        });
        Schema::create('student_tutoring_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained('student_tutoring_activities')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->text('payload');
            $table->string('moderation', 12)->nullable();
            $table->timestamp('assented_at')->nullable();
            $table->timestamps();
            $table->unique(['activity_id', 'enrollment_id']);
        });
        Schema::create('student_tutoring_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('category', 16)->nullable();
            $table->text('content');
            $table->text('feedback')->nullable();
            $table->foreignId('feedback_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('attended_at')->nullable();
            $table->timestamp('referred_at')->nullable();
            $table->foreignId('referred_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['enrollment_id', 'kind', 'created_at']);
        });
        Schema::create('student_goal_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entry_id')->constrained('student_tutoring_entries')->restrictOnDelete();
            $table->unsignedTinyInteger('progress');
            $table->text('note');
            $table->timestamp('created_at');
            $table->index(['entry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_goal_updates');
        Schema::dropIfExists('student_tutoring_entries');
        Schema::dropIfExists('student_tutoring_responses');
        Schema::dropIfExists('student_tutoring_accesses');
        Schema::dropIfExists('student_research_permissions');
        Schema::dropIfExists('student_tutoring_activities');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['student_profile_id']);
            $table->dropUnique(['student_profile_id']);
            $table->dropColumn('student_profile_id');
        });
    }
};
