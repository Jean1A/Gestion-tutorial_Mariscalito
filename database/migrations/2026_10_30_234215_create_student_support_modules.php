<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_profiles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->foreignId('school_year_id')->constrained()->restrictOnDelete();
            foreach (['family_context', 'health_context', 'interests', 'support_needs'] as $field) {
                $t->text($field)->nullable();
            }
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['student_id', 'school_year_id']);
        });
        Schema::create('support_records', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->foreignId('section_id')->constrained()->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('course_offering_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('title', 160);
            $t->text('description');
            $t->text('agreements')->nullable();
            $t->string('visibility', 20)->default('reserved');
            $t->string('severity', 20)->default('low');
            $t->string('status', 20)->default('open');
            $t->date('occurred_on');
            $t->date('due_on')->nullable();
            $t->text('closure_note')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->index(['section_id', 'kind', 'status']);
            $t->index(['assigned_to', 'status']);
        });
        Schema::create('support_follow_ups', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('support_record_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->text('note');
            $t->date('next_on')->nullable();
            $t->timestamps();
        });
        Schema::create('tutoring_sessions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('section_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('topic', 160);
            $t->date('scheduled_on');
            $t->text('objectives');
            $t->text('outcomes')->nullable();
            $t->json('attendees')->nullable();
            $t->string('evidence_path')->nullable();
            $t->string('evidence_mime')->nullable();
            $t->string('status', 20)->default('planned');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->index(['section_id', 'scheduled_on']);
        });
        Schema::create('family_messages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('student_id')->constrained()->restrictOnDelete();
            $t->foreignId('tutor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('guardian_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $t->text('body');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['student_id', 'tutor_id', 'guardian_id']);
        });
    }

    public function down(): void
    {
        foreach (['family_messages', 'tutoring_sessions', 'support_follow_ups', 'support_records', 'support_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
