<?php

namespace Database\Factories;

use App\Models\CourseOffering;
use App\Models\TutoringSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TutoringSession> */
class TutoringSessionFactory extends Factory
{
    public function definition(): array
    {
        return ['section_id' => fn () => CourseOffering::factory()->create()->section_id, 'user_id' => User::factory(), 'topic' => 'Convivencia escolar', 'objectives' => 'Reconocer acuerdos de convivencia.', 'scheduled_on' => '2026-10-29', 'status' => 'planned', 'revision' => 1];
    }
}
