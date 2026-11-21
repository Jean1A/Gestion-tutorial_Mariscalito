<?php

namespace Database\Factories;

use App\Models\FamilyMeeting;
use App\Models\MeetingAttendance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MeetingAttendance> */
class MeetingAttendanceFactory extends Factory
{
    public function definition(): array
    {
        return ['family_meeting_id' => FamilyMeeting::factory(), 'user_id' => User::factory(), 'attended' => true, 'recorded_by' => User::factory()];
    }
}
