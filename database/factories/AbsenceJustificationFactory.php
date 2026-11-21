<?php

namespace Database\Factories;

use App\Models\AbsenceJustification;
use App\Models\CourseAttendanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AbsenceJustification> */
class AbsenceJustificationFactory extends Factory
{
    public function definition(): array
    {
        return ['course_attendance_record_id' => CourseAttendanceRecord::factory(), 'user_id' => User::factory(), 'reason' => 'family', 'description' => 'La familia presenta el sustento de la inasistencia.', 'status' => 'pending'];
    }
}
