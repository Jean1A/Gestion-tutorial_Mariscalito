<?php

namespace Database\Factories;

use App\Models\SupportFollowUp;
use App\Models\SupportRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportFollowUp> */
class SupportFollowUpFactory extends Factory
{
    public function definition(): array
    {
        return ['support_record_id' => SupportRecord::factory(), 'user_id' => User::factory(), 'note' => 'Seguimiento ficticio de los acuerdos.'];
    }
}
