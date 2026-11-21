<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public const COORDINATOR_PERMISSIONS = ['users.manage', 'academics.manage', 'students.manage', 'students.view', 'attendance.view', 'communications.send', 'reports.view', 'courses.manage', 'family.manage', 'tutors.coordinate'];

    public function run(): void
    {
        $labels = [
            'tutors.coordinate' => 'Coordinar tutores y supervisar sus actividades',
            'users.manage' => 'Gestionar usuarios',
            'academics.manage' => 'Gestionar estructura académica',
            'students.manage' => 'Gestionar estudiantes y vínculos',
            'students.view' => 'Consultar estudiantes dentro del ámbito autorizado',
            'attendance.record' => 'Registrar asistencia de secciones asignadas',
            'attendance.view' => 'Consultar asistencia dentro del ámbito autorizado',
            'communications.send' => 'Enviar citaciones y comunicados',
            'communications.respond' => 'Responder comunicaciones como apoderado',
            'settings.manage' => 'Gestionar configuración',
            'reports.view' => 'Consultar reportes dentro del ámbito autorizado',
            'courses.manage' => 'Gestionar cursos y docentes',
            'courses.record' => 'Registrar asistencia y logros de cursos propios',
            'family.manage' => 'Gestionar escuela de padres',
        ];
        foreach ($labels as $name => $label) {
            Permission::firstOrCreate(['name' => $name], ['label' => $label]);
        }
        $grants = [
            'administrator' => ['users.manage', 'academics.manage', 'students.manage', 'students.view', 'attendance.view', 'settings.manage', 'courses.manage', 'family.manage', 'tutors.coordinate'],
            'tutor' => ['students.view', 'attendance.record', 'attendance.view', 'communications.send', 'courses.record'],
            'assistant' => ['students.view', 'attendance.record', 'attendance.view', 'courses.record'],
            'guardian' => ['students.view', 'attendance.view', 'communications.respond'],
            'teacher' => ['courses.record'],
            'coordinator' => self::COORDINATOR_PERMISSIONS,
            'student' => [],
        ];
        foreach (RoleName::cases() as $name) {
            $role = Role::firstOrCreate(['name' => $name->value], ['label' => $name->label()]);
            // No sobrescribir los permisos modificados por el colegio al repetir el seeder.
            if ($role->wasRecentlyCreated) {
                $role->permissions()->sync(Permission::whereIn('name', $grants[$name->value])->pluck('id'));
            }
        }
    }
}
