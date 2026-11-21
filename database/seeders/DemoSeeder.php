<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\EducationLevel;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Role;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public const ACCOUNTS = [
        '90000001' => RoleName::Administrator,
        '90000004' => RoleName::Tutor,
        '90000005' => RoleName::Assistant,
        '90000007' => RoleName::Guardian,
        '90000008' => RoleName::Teacher,
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Los datos de demostración solo se permiten en local o pruebas.');
        }
        $password = env('DEMO_PASSWORD');
        if (! is_string($password) || strlen($password) < 12 || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[a-z]/', $password) || ! preg_match('/[0-9]/', $password)) {
            throw new RuntimeException('Define DEMO_PASSWORD con al menos 12 caracteres, mayúsculas, minúsculas y números.');
        }
        $this->call(RoleSeeder::class);
        foreach (self::ACCOUNTS as $dni => $role) {
            $user = User::withTrashed()->firstOrCreate(['dni' => (string) $dni], [
                'name' => 'Demo '.$role->label(),
                'email' => $role->value.'@example.test',
                'password' => $password,
                'is_active' => true,
                'must_change_password' => true,
            ]);
            if ($user->wasRecentlyCreated) {
                $user->roles()->attach(Role::where('name', $role->value)->sole());
            }
            if ($user->email !== $role->value.'@example.test' || ! $user->hasRole($role)) {
                throw new RuntimeException('El DNI '.$dni.' no corresponde a una cuenta activa del conjunto de demostración.');
            }
        }
        $year = SchoolYear::firstOrCreate(['year' => 2026], ['starts_on' => '2026-03-02', 'ends_on' => '2026-12-18']);
        $level = EducationLevel::firstOrCreate(['name' => 'Secundaria']);
        $grade = Grade::firstOrCreate(['education_level_id' => $level->id, 'name' => 'Primero']);
        $section = Section::firstOrCreate(['school_year_id' => $year->id, 'grade_id' => $grade->id, 'name' => 'A']);
        $student = Student::firstOrCreate(['dni' => '91000001'], ['first_name' => 'Estudiante', 'last_name' => 'Demostración', 'birth_date' => '2013-05-10']);
        Enrollment::firstOrCreate(['student_id' => $student->id, 'school_year_id' => $year->id], ['section_id' => $section->id, 'starts_on' => $year->starts_on]);
        $administrator = User::where('dni', '90000001')->firstOrFail();
        User::where('dni', '90000007')->firstOrFail()->students()->syncWithoutDetaching([$student->id => ['relationship' => 'Apoderado de prueba', 'is_active' => true, 'verified_by' => $administrator->id, 'verified_at' => now()]]);
        DB::transaction(function () use ($section): void {
            Section::whereKey($section->id)->lockForUpdate()->firstOrFail();
            $assignments = DB::table('tutor_assignments')->where('section_id', $section->id);
            if (! $assignments->exists()) {
                DB::table('tutor_assignments')->insert([
                    'user_id' => User::where('dni', '90000004')->value('id'),
                    'section_id' => $section->id,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
        DB::table('assistant_assignments')->updateOrInsert(['user_id' => User::where('dni', '90000005')->value('id'), 'grade_id' => $grade->id, 'school_year_id' => $year->id], ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}
