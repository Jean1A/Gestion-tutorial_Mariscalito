<?php

namespace Database\Factories;

use App\Models\FamilyWorkshop;
use App\Models\WorkshopLesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkshopLesson> */
class WorkshopLessonFactory extends Factory
{
    public function definition(): array
    {
        return ['family_workshop_id' => FamilyWorkshop::factory(), 'title' => 'Contenido del taller', 'content' => 'Contenido de formación para las familias.', 'position' => 1];
    }
}
