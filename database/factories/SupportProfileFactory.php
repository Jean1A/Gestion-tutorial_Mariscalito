<?php

namespace Database\Factories;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\SupportProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportProfile> */
class SupportProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['student_id' => Student::factory(), 'school_year_id' => fn () => SchoolYear::firstOrCreate(['year' => 2026], ['starts_on' => '2026-03-02', 'ends_on' => '2026-12-18'])->id, 'family_context' => 'Contexto familiar ficticio', 'updated_by' => User::factory(), 'revision' => 1];
    }
}
