<?php

use App\Models\AcademicPeriod;
use App\Models\EducationLevel;
use App\Models\Grade;
use App\Models\SchoolYear;
use App\Models\Section;

return [
    'years' => ['model' => SchoolYear::class, 'label' => 'Años escolares', 'fields' => ['year' => 'Año', 'starts_on' => 'Inicio', 'ends_on' => 'Fin', 'is_active' => 'Activo']],
    'levels' => ['model' => EducationLevel::class, 'label' => 'Niveles', 'fields' => ['name' => 'Nombre', 'is_active' => 'Activo']],
    'grades' => ['model' => Grade::class, 'label' => 'Grados', 'fields' => ['education_level_id' => 'Nivel', 'name' => 'Nombre', 'is_active' => 'Activo']],
    'sections' => ['model' => Section::class, 'label' => 'Secciones', 'fields' => ['school_year_id' => 'Año escolar', 'grade_id' => 'Grado', 'name' => 'Nombre', 'is_active' => 'Activo']],
    'periods' => ['model' => AcademicPeriod::class, 'label' => 'Períodos', 'fields' => ['school_year_id' => 'Año escolar', 'name' => 'Nombre', 'starts_on' => 'Inicio', 'ends_on' => 'Fin', 'is_active' => 'Activo']],
];
