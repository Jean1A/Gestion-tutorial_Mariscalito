<?php

namespace Database\Factories;

use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Models\SupportRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportRecord> */
class SupportRecordFactory extends Factory
{
    public function definition(): array
    {
        return ['section_id' => fn () => CourseOffering::factory()->create()->section_id, 'student_id' => function (array $a) {
            $section = Section::findOrFail($a['section_id']);
            $student = Student::factory()->create();
            Enrollment::create(['student_id' => $student->id, 'section_id' => $section->id, 'school_year_id' => $section->school_year_id, 'starts_on' => '2026-03-02']);

            return $student->id;
        }, 'author_id' => User::factory(), 'kind' => 'interview', 'title' => 'Entrevista de seguimiento', 'description' => 'Acuerdos para acompañar el proceso escolar.', 'visibility' => 'reserved', 'severity' => 'low', 'status' => 'open', 'occurred_on' => '2026-10-29', 'revision' => 1];
    }
}
