<?php

namespace Database\Factories;

use App\Models\FamilyMessage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FamilyMessage> */
class FamilyMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['student_id' => Student::factory(), 'tutor_id' => User::factory(), 'guardian_id' => User::factory(), 'sender_id' => fn (array $a) => $a['tutor_id'], 'body' => 'Mensaje ficticio de coordinación familiar.'];
    }
}
