<?php

namespace Database\Factories;

use App\Models\CourseAttendanceSession;
use App\Models\CourseOffering;
use App\Models\SchoolDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseAttendanceSession> */
class CourseAttendanceSessionFactory extends Factory
{
    public function definition(): array
    {
        return ['course_offering_id' => CourseOffering::factory(), 'school_day_id' => function (array $a) {
            $offering = CourseOffering::findOrFail($a['course_offering_id']);

            return (SchoolDay::where('school_year_id', $offering->section->school_year_id)->whereDate('date', now()->toDateString())->first() ?? SchoolDay::create(['school_year_id' => $offering->section->school_year_id, 'date' => now()->toDateString(), 'is_instructional' => true]))->id;
        }, 'recorded_by' => fn (array $a) => CourseOffering::findOrFail($a['course_offering_id'])->teacher_id, 'revision' => 1];
    }
}
