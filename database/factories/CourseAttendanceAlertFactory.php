<?php

namespace Database\Factories;

use App\Models\CourseAttendanceAlert;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseAttendanceAlert> */
class CourseAttendanceAlertFactory extends Factory
{
    public function definition(): array
    {
        return ['course_offering_id' => CourseOffering::factory(), 'enrollment_id' => fn (array $a) => Enrollment::create(['student_id' => Student::factory()->create()->id, 'school_year_id' => CourseOffering::findOrFail($a['course_offering_id'])->section->school_year_id, 'section_id' => CourseOffering::findOrFail($a['course_offering_id'])->section_id, 'starts_on' => '2026-03-02'])->id, 'type' => 'percentage', 'threshold' => 20, 'observed_value' => 25, 'is_active' => true];
    }
}
