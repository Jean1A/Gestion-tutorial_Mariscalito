<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_periods', fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_day_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->unique(['section_id', 'school_day_id']);
            $table->timestamps();
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['present', 'late', 'justified_absence', 'unjustified_absence']);
            $table->time('arrival_time')->nullable();
            $table->string('justification', 500)->nullable();
            $table->unique(['attendance_session_id', 'enrollment_id']);
            $table->index(['enrollment_id', 'status']);
            $table->timestamps();
        });
        Schema::create('attendance_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->decimal('threshold', 6, 2);
            $table->decimal('observed_value', 6, 2);
            $table->boolean('is_active')->default(true)->index();
            $table->unique(['enrollment_id', 'type', 'threshold'], 'attendance_alert_threshold_unique');
            $table->timestamps();
        });
        Schema::create('student_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->string('path');
            $table->enum('format', ['csv', 'xlsx']);
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->string('failure_message')->nullable();
            $table->timestamps();
        });
        Schema::create('student_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_import_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->boolean('succeeded');
            $table->string('error', 500)->nullable();
            $table->unique(['student_import_id', 'row_number']);
        });
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_year_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['notice', 'appointment']);
            $table->string('title', 150);
            $table->text('body');
            $table->dateTime('scheduled_at')->nullable();
            $table->string('location', 180)->nullable();
            $table->timestamps();
        });
        Schema::create('communication_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('communication_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->enum('response', ['pending', 'confirmed', 'reschedule_requested'])->default('pending');
            $table->string('response_note', 500)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unique(['communication_id', 'user_id', 'student_id'], 'communication_recipient_unique');
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notifications', 'communication_recipients', 'communications', 'student_import_rows', 'student_imports', 'attendance_alerts', 'attendance_records', 'attendance_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('academic_periods', fn (Blueprint $table) => $table->dropColumn('is_active'));
    }
};
