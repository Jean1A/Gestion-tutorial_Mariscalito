<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\EducationLevel;
use App\Models\Grade;
use App\Models\Role;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CourseOffering> */
class CourseOfferingFactory extends Factory
{
    public function definition(): array
    {
        return ['course_id' => Course::factory(), 'section_id' => function () {
            $year = SchoolYear::firstOrCreate(['year' => 2026], ['starts_on' => '2026-03-02', 'ends_on' => '2026-12-18']);
            $level = EducationLevel::firstOrCreate(['name' => 'Secundaria']);
            $grade = Grade::firstOrCreate(['education_level_id' => $level->id, 'name' => 'Primero']);

            return Section::firstOrCreate(['school_year_id' => $year->id, 'grade_id' => $grade->id, 'name' => fake()->unique()->lexify('??')])->id;
        }, 'teacher_id' => function () {
            $user = User::factory()->create();
            $role = Role::firstOrCreate(['name' => 'teacher'], ['label' => 'Docente']);
            $user->roles()->attach($role);

            return $user->id;
        }, 'content' => 'Contenido del curso para las familias.', 'is_active' => true];
    }
}
