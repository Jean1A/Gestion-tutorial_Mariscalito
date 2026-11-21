<?php

namespace Database\Seeders;

use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseAttendanceRecord;
use App\Models\CourseAttendanceSession;
use App\Models\CourseOffering;
use App\Models\CourseResult;
use App\Models\Enrollment;
use App\Models\FamilyMeeting;
use App\Models\FamilyWorkshop;
use App\Models\Role;
use App\Models\SchoolDay;
use App\Models\Student;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Database\Seeder;

class ParentPortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Solo se permiten datos ficticios en local o pruebas.');
        }
        $this->call([DemoSeeder::class, PortalPermissionSeeder::class]);
        $admin = User::where('dni', '90000001')->firstOrFail();
        $teacher = User::where('dni', '90000008')->firstOrFail();
        $tutor = User::where('dni', '90000004')->firstOrFail();
        $tutor->roles()->syncWithoutDetaching(Role::where('name', 'teacher')->pluck('id'));
        $student = Student::where('dni', '91000001')->firstOrFail();
        $enrollment = $student->enrollments()->latest('starts_on')->firstOrFail();
        $section = $enrollment->section;
        $year = $section->schoolYear;
        $other = Student::firstOrCreate(['dni' => '91000002'], ['first_name' => 'Estudiante Dos', 'last_name' => 'Demostración', 'birth_date' => '2013-06-15']);
        $otherEnrollment = Enrollment::firstOrCreate(['student_id' => $other->id, 'school_year_id' => $year->id, 'starts_on' => $year->starts_on->toDateString()], ['section_id' => $section->id]);
        $guardian = User::where('dni', '90000007')->firstOrFail();
        $guardian->students()->syncWithoutDetaching([$other->id => ['relationship' => 'Apoderado de prueba', 'is_active' => true, 'verified_by' => $admin->id, 'verified_at' => now()]]);
        $preview = User::firstOrCreate(['dni' => '90000009'], ['name' => 'Demo Portal de Padres', 'email' => 'portal-preview@example.test', 'password' => env('DEMO_PASSWORD'), 'is_active' => true, 'must_change_password' => true]);
        $preview->roles()->syncWithoutDetaching(Role::where('name', 'guardian')->pluck('id'));
        foreach ([$student, $other] as $child) {
            $preview->students()->syncWithoutDetaching([$child->id => ['relationship' => 'Cuenta de demostración', 'is_active' => true, 'verified_by' => $admin->id, 'verified_at' => now()]]);
        }
        // Consentimiento simulado solo para esta cuenta de revisión con estudiantes ficticios.
        if (! app(ConsentService::class)->hasValidConsent($preview)) {
            app(ConsentService::class)->accept($preview);
        }
        $period = AcademicPeriod::firstOrCreate(['school_year_id' => $year->id, 'name' => 'Tercer trimestre (demostración)'], ['starts_on' => '2026-09-01', 'ends_on' => '2026-12-18', 'is_active' => true]);
        foreach (['Matemática' => 'calculator', 'Comunicación' => 'book', 'Ciencia y Tecnología' => 'flask', 'Ciencias Sociales' => 'globe'] as $name => $icon) {
            $course = Course::firstOrCreate(['name' => $name], ['icon' => $icon]);
            $offering = CourseOffering::firstOrCreate(['course_id' => $course->id, 'section_id' => $section->id], ['teacher_id' => $name === 'Matemática' ? $tutor->id : $teacher->id, 'content' => "Contenido ficticio para probar el portal.\n\nAquí el docente publicará los temas y orientaciones del curso."]);
            foreach (['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-26'] as $index => $date) {
                if ($date > now()->toDateString()) {
                    continue;
                }
                $day = SchoolDay::where('school_year_id', $year->id)->whereDate('date', $date)->first() ?? SchoolDay::create(['school_year_id' => $year->id, 'date' => $date, 'is_instructional' => true, 'description' => 'Día ficticio para demostración del portal']);
                if (! $day->is_instructional) {
                    continue;
                }
                $session = CourseAttendanceSession::firstOrCreate(['course_offering_id' => $offering->id, 'school_day_id' => $day->id], ['recorded_by' => $offering->teacher_id]);
                foreach ([$enrollment, $otherEnrollment] as $entry) {
                    CourseAttendanceRecord::firstOrCreate(['course_attendance_session_id' => $session->id, 'enrollment_id' => $entry->id], ['status' => $index >= 4 ? 'unjustified_absence' : 'present', 'arrival_time' => $index >= 4 ? null : '08:00:00']);
                }
            }
            CourseResult::firstOrCreate(['course_offering_id' => $offering->id, 'enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id], ['achievement' => $name === 'Matemática' ? 'B' : 'A', 'is_published' => true, 'recorded_by' => $offering->teacher_id]);
        }
        foreach (['Prevención del bullying', 'Convivencia familiar'] as $title) {
            $workshop = FamilyWorkshop::firstOrCreate(['title' => $title], ['user_id' => $admin->id, 'description' => 'Taller ficticio para evaluar el flujo de escuela de padres.', 'is_published' => true, 'is_active' => true]);
            foreach (['Escuchar y acompañar', 'Acuerdos para la familia', 'Aplicar lo aprendido'] as $index => $lesson) {
                $workshop->lessons()->firstOrCreate(['position' => $index + 1], ['title' => $lesson, 'content' => "Contenido de demostración.\n\nEste espacio permite al colegio compartir orientaciones con las familias. Lee el material y marca el contenido como completado para probar tu progreso. No se registran evaluaciones psicológicas."]);
            }
        }
        FamilyMeeting::firstOrCreate(['title' => 'Reunión de familias (demostración)', 'section_id' => $section->id], ['user_id' => $admin->id, 'scheduled_at' => '2026-10-23 16:00:00', 'location' => 'Sala de tutoría · datos ficticios', 'is_published' => true, 'is_active' => true]);
    }
}
