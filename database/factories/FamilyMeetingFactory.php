<?php

namespace Database\Factories;

use App\Models\FamilyMeeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FamilyMeeting> */
class FamilyMeetingFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'title' => 'Reunión de acompañamiento', 'scheduled_at' => now()->subDay(), 'location' => 'Sala de tutoría', 'is_published' => true, 'is_active' => true];
    }
}
