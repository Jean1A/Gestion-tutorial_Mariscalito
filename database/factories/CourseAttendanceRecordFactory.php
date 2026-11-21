<?php

namespace Database\Factories;

use App\Models\CourseAttendanceRecord;
use App\Models\CourseAttendanceSession;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseAttendanceRecord> */
class CourseAttendanceRecordFactory extends Factory
{
    public function definition(): array
    {
        return ['course_attendance_session_id' => CourseAttendanceSession::factory(), 'enrollment_id' => function (array $a) {
            $session = CourseAttendanceSession::findOrFail($a['course_attendance_session_id']);

            return Enrollment::create(['student_id' => Student::factory()->create()->id, 'school_year_id' => $session->offering->section->school_year_id, 'section_id' => $session->offering->section_id, 'starts_on' => '2026-03-02'])->id;
        }, 'status' => 'unjustified_absence', 'arrival_time' => null];
    }
}
