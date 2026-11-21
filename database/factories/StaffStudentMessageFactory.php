<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\StaffStudentMessage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffStudentMessage> */
class StaffStudentMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'course_offering_id' => CourseOffering::factory(),
            'enrollment_id' => function (array $attributes): int {
                $section = CourseOffering::findOrFail($attributes['course_offering_id'])->section;

                return Enrollment::create([
                    'student_id' => Student::factory()->create()->id,
                    'section_id' => $section->id,
                    'school_year_id' => $section->school_year_id,
                    'starts_on' => $section->schoolYear->starts_on,
                ])->id;
            },
            'tutor_id' => function (array $attributes): int {
                $tutor = User::factory()->create();
                $tutor->roles()->attach(Role::where('name', RoleName::Tutor->value)->firstOrFail());
                $tutor->tutoredSections()->attach(CourseOffering::findOrFail($attributes['course_offering_id'])->section_id);

                return $tutor->id;
            },
            'teacher_id' => fn (array $attributes): int => CourseOffering::findOrFail($attributes['course_offering_id'])->teacher_id,
            'sender_id' => fn (array $attributes): int => $attributes['teacher_id'],
            'body' => fake()->sentence(),
        ];
    }
}
