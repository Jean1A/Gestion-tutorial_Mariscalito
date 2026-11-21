<?php

namespace Database\Factories;

use App\Models\FamilyWorkshop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FamilyWorkshop> */
class FamilyWorkshopFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'title' => fake()->sentence(4), 'description' => 'Taller de acompañamiento familiar.', 'is_published' => true, 'is_active' => true];
    }
}
