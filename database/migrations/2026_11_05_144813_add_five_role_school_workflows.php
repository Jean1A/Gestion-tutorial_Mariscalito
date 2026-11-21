<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_years', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
        });
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('is_tutoring')->default(false);
        });
        DB::table('courses')->whereIn('name', ['Tutoría', 'Tutoria'])->update(['is_tutoring' => true]);
        Schema::table('support_records', function (Blueprint $table): void {
            $table->string('dimension', 20)->nullable();
            $table->text('family_summary')->nullable();
            $table->string('location', 160)->nullable();
        });
        Schema::table('tutoring_sessions', function (Blueprint $table): void {
            $table->json('dimensions')->nullable();
            $table->json('materials')->nullable();
        });
        Schema::create('staff_student_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_offering_id')->constrained();
            $table->foreignId('enrollment_id')->constrained();
            $table->foreignId('tutor_id')->constrained('users');
            $table->foreignId('teacher_id')->constrained('users');
            $table->foreignId('sender_id')->constrained('users');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['course_offering_id', 'enrollment_id', 'tutor_id', 'teacher_id'], 'staff_message_thread');
        });
        $permissions = DB::table('permissions')->whereIn('name', RoleSeeder::COORDINATOR_PERMISSIONS)->pluck('id');
        if ($permissions->count() === count(RoleSeeder::COORDINATOR_PERMISSIONS)) {
            $coordinatorId = DB::table('roles')->insertGetId(['name' => 'coordinator', 'label' => 'Coordinador de tutoría', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('permission_role')->insert($permissions->map(fn (int $id): array => ['permission_id' => $id, 'role_id' => $coordinatorId])->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_student_messages');
        DB::table('roles')->where('name', 'coordinator')->delete();
        Schema::table('tutoring_sessions', fn (Blueprint $table) => $table->dropColumn(['dimensions', 'materials']));
        Schema::table('support_records', fn (Blueprint $table) => $table->dropColumn(['dimension', 'family_summary', 'location']));
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('is_tutoring'));
        Schema::table('school_years', fn (Blueprint $table) => $table->dropColumn('closed_at'));
    }
};
