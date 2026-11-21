<?php

namespace Database\Factories;

use App\Models\FamilyWorkshop;
use App\Models\User;
use App\Models\WorkshopSurvey;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkshopSurvey> */
class WorkshopSurveyFactory extends Factory
{
    public function definition(): array
    {
        return ['family_workshop_id' => FamilyWorkshop::factory(), 'user_id' => User::factory(), 'rating' => 4, 'comment' => null];
    }
}
