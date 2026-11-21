<?php

namespace Database\Seeders;

use App\Models\AbsenceJustification;
use App\Models\CourseAttendanceRecord;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdvisorPortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('La vista de demostración solo se permite en local o pruebas.');
        }
        $password = env('DEMO_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Prepara primero las credenciales ficticias con initialize-demo.php.');
        }
        $student = Student::where('dni', '91000001')->firstOrFail();
        $section = $student->enrollments()->latest('starts_on')->firstOrFail()->section;
        $advisor = User::firstOrCreate(['dni' => '90000010'], ['name' => 'Asesor de Demostración', 'email' => 'advisor-preview@example.test', 'password' => $password, 'is_active' => true, 'must_change_password' => true]);
        $advisor->roles()->syncWithoutDetaching(Role::where('name', 'tutor')->pluck('id'));
        DB::table('tutor_assignments')->insertOrIgnore(['user_id' => $advisor->id, 'section_id' => $section->id, 'is_active' => true]);
        $guardian = User::where('dni', '90000009')->firstOrFail();
        $record = CourseAttendanceRecord::whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))->where('status', 'unjustified_absence')->whereHas('session.offering.course', fn ($q) => $q->where('name', 'Matemática'))->latest('id')->first();
        if ($record) {
            AbsenceJustification::firstOrCreate(['course_attendance_record_id' => $record->id], ['user_id' => $guardian->id, 'reason' => 'family', 'description' => 'Solicitud ficticia para revisar el flujo del asesor. No contiene datos reales de una familia.', 'status' => 'pending']);
        }
    }
}
