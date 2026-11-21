<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkshopCompletion;
use App\Models\WorkshopLesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkshopCompletion> */
class WorkshopCompletionFactory extends Factory
{
    public function definition(): array
    {
        return ['workshop_lesson_id' => WorkshopLesson::factory(), 'user_id' => User::factory()];
    }
}
