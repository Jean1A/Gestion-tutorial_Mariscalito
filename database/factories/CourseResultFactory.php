<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\CourseOffering;
use App\Models\CourseResult;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseResult> */
class CourseResultFactory extends Factory
{
    public function definition(): array
    {
        return ['course_offering_id' => CourseOffering::factory(), 'enrollment_id' => fn (array $a) => Enrollment::create(['student_id' => Student::factory()->create()->id, 'school_year_id' => CourseOffering::findOrFail($a['course_offering_id'])->section->school_year_id, 'section_id' => CourseOffering::findOrFail($a['course_offering_id'])->section_id, 'starts_on' => '2026-03-02'])->id, 'academic_period_id' => fn (array $a) => AcademicPeriod::firstOrCreate(['school_year_id' => CourseOffering::findOrFail($a['course_offering_id'])->section->school_year_id, 'name' => 'Primer trimestre'], ['starts_on' => '2026-03-02', 'ends_on' => '2026-05-29'])->id, 'achievement' => 'A', 'is_published' => false, 'recorded_by' => fn (array $a) => CourseOffering::findOrFail($a['course_offering_id'])->teacher_id];
    }
}
