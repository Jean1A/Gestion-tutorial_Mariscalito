<?php

namespace Database\Factories;

use App\Models\FamilyWorkshop;
use App\Models\ForumPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ForumPost> */
class ForumPostFactory extends Factory
{
    public function definition(): array
    {
        return ['family_workshop_id' => FamilyWorkshop::factory(), 'user_id' => User::factory(), 'body' => 'Participación de una familia en el taller.', 'status' => 'pending'];
    }
}
