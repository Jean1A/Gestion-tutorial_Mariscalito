<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AbsenceJustification;
use App\Models\AcademicPeriod;
use App\Models\Communication;
use App\Models\CommunicationRecipient;
use App\Models\CourseAttendanceAlert;
use App\Models\CourseAttendanceRecord;
use App\Models\CourseAttendanceSession;
use App\Models\CourseOffering;
use App\Models\CourseResult;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SchoolDay;
use App\Models\Student;
use App\Models\User;
use App\Services\ConsentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AdvisorPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
    }

    public function test_dashboard_counts_only_assigned_course_records_and_private_requests(): void
    {
        [$tutor,$course,$student,$enrollment] = $this->section();
        [,$foreign,$other,$otherEnrollment] = $this->section();
        $record = $this->record($course, $enrollment, 'unjustified_absence');
        $this->record($foreign, $otherEnrollment, 'present');
        AbsenceJustification::factory()->create(['course_attendance_record_id' => $record->id, 'description' => 'Sustento propio']);
        AbsenceJustification::factory()->create(['course_attendance_record_id' => $this->record($foreign, $otherEnrollment, 'unjustified_absence', '2026-10-26')->id, 'description' => 'Sustento ajeno']);
        CourseAttendanceAlert::factory()->create(['course_offering_id' => $course->id, 'enrollment_id' => $enrollment->id]);
        CourseAttendanceAlert::factory()->create(['course_offering_id' => $foreign->id, 'enrollment_id' => $otherEnrollment->id]);
        $this->login($tutor);
        $this->get(route('dashboard'))->assertOk()->assertViewIs('advisor.home')->assertViewHas('stats', fn ($stats) => $stats['sections'] === 1 && $stats['students'] === 1 && $stats['courses'] === 1 && $stats['pending_justifications'] === 1 && $stats['registered_today'] === 1 && $stats['absent_today'] === 1 && $stats['present_today'] === 0)->assertSee($student->full_name)->assertDontSee($other->full_name);
        $this->get(route('justifications.index'))->assertOk()->assertSee('Sustento propio')->assertDontSee('Sustento ajeno');
        $this->get(route('advisor.alerts'))->assertOk()->assertSee($student->full_name)->assertDontSee($other->full_name);
        $this->get(route('courses.mine'))->assertOk()->assertSee($course->course->name)->assertDontSee($foreign->course->name);
        $this->get(route('courses.record', $course))->assertOk()->assertSee($student->full_name)->assertDontSee('Registrar nivel de logro');
        $this->get(route('sections.show', $course->section))->assertOk()->assertSee($student->full_name)->assertDontSee($other->full_name);
        $this->get(route('notifications.index'))->assertOk();
    }

    public function test_student_filters_reject_foreign_sections_and_escape_names(): void
    {
        [$tutor,$course,$student] = $this->section();
        [,$foreign,$other] = $this->section();
        $student->update(['first_name' => '<script>alert(1)</script>']);
        $this->login($tutor);
        $this->get(route('advisor.sections'))->assertOk()->assertViewHas('sections', fn ($rows) => $rows->count() === 1);
        $this->get(route('advisor.students', ['q' => $student->dni, 'section_id' => $course->section_id]))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee($other->full_name);
        $this->get(route('advisor.students', ['q' => "%' OR 1=1 --"]))->assertOk()->assertViewHas('students', fn ($rows) => $rows->isEmpty());
        $this->get(route('advisor.students', ['section_id' => $foreign->section_id]))->assertForbidden();
        $this->get(route('advisor.alerts', ['section_id' => $foreign->section_id]))->assertForbidden();
        $this->get(route('advisor.students', ['q' => str_repeat('a', 101)]))->assertSessionHasErrors('q');
    }

    public function test_student_profile_excludes_other_sections_and_unpublished_results(): void
    {
        [$tutor,$course,$student,$enrollment] = $this->section();
        [,$foreign] = $this->section();
        $otherEnrollment = Enrollment::create(['student_id' => $student->id, 'school_year_id' => $enrollment->school_year_id, 'section_id' => $foreign->section_id, 'starts_on' => '2026-06-01']);
        $this->record($course, $enrollment, 'present');
        $this->record($foreign, $otherEnrollment, 'unjustified_absence');
        $published = CourseResult::factory()->create(['course_offering_id' => $course->id, 'enrollment_id' => $enrollment->id, 'is_published' => true, 'achievement' => 'B']);
        CourseResult::factory()->create(['course_offering_id' => $foreign->id, 'enrollment_id' => $otherEnrollment->id, 'is_published' => true]);
        CourseResult::factory()->create(['course_offering_id' => $course->id, 'enrollment_id' => $enrollment->id, 'academic_period_id' => AcademicPeriod::create(['school_year_id' => $enrollment->school_year_id, 'name' => 'Borrador de prueba', 'starts_on' => '2026-06-01', 'ends_on' => '2026-08-31'])->id, 'is_published' => false]);
        $this->login($tutor);
        $this->get(route('advisor.student', $student))->assertOk()->assertViewHas('history', fn ($rows) => $rows->count() === 1)->assertViewHas('summary', fn ($summary) => $summary['percentage'] === 0.0 && $summary['registered_sessions'] === 1)->assertViewHas('results', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $published->id)->assertDontSee($foreign->course->name);
        $this->get(route('students.show', $student))->assertOk()->assertViewIs('advisor.student');
    }

    public function test_role_switch_and_revoked_assignments_enforce_scope_on_all_advisor_routes(): void
    {
        [$tutor,$course,$student] = $this->section();
        [,$foreign,$other] = $this->section();
        $tutor->roles()->attach(Role::where('name', 'coordinator')->firstOrFail());
        $this->login($tutor);
        $this->get(route('advisor.students'))->assertForbidden();
        $this->post(route('role.switch'), ['role' => 'tutor'])->assertRedirect(route('dashboard'));
        $this->get(route('advisor.student', $student))->assertOk();
        $this->get(route('advisor.student', $other))->assertForbidden();
        $this->get(route('courses.record', $foreign))->assertForbidden();
        DB::table('tutor_assignments')->where('user_id', $tutor->id)->update(['is_active' => false]);
        $this->get(route('advisor.student', $student))->assertForbidden();
        $this->get(route('sections.show', $course->section))->assertForbidden();
        $this->get(route('courses.record', $course))->assertForbidden();
        $this->get(route('advisor.students'))->assertOk()->assertViewHas('students', fn ($rows) => $rows->isEmpty());
    }

    public function test_advisor_gate_requires_tutor_active_role_and_students_permission(): void
    {
        foreach (RoleName::cases() as $role) {
            $user = $this->user($role);
            $this->login($user);
            $this->assertSame($role === RoleName::Tutor, Gate::forUser($user)->allows('advisor-portal'));
        }
        $this->login($this->user(RoleName::Teacher));
        $this->get(route('advisor.sections'))->assertForbidden();
        $tutor = $this->user(RoleName::Tutor);
        Role::where('name', 'tutor')->firstOrFail()->permissions()->detach(DB::table('permissions')->where('name', 'students.view')->value('id'));
        $this->login($tutor);
        $this->get(route('advisor.students'))->assertForbidden();
        auth()->logout();
        $this->get(route('advisor.students'))->assertRedirect(route('login'));
    }

    public function test_communication_prefill_receipts_and_revocation_are_scoped_to_assigned_families(): void
    {
        [$tutor,$course,$student] = $this->section();
        [,, $other] = $this->section();
        $guardian = $this->user(RoleName::Guardian);
        $guardian->students()->attach($student, ['relationship' => 'Apoderado', 'is_active' => true, 'verified_by' => $tutor->id, 'verified_at' => now()]);
        app(ConsentService::class)->accept($guardian);
        $communication = Communication::create(['user_id' => $tutor->id, 'school_year_id' => $course->section->school_year_id, 'type' => 'appointment', 'title' => 'Cita de seguimiento', 'body' => 'Orientación para la familia.', 'scheduled_at' => now()->addDay(), 'location' => 'Sala de tutoría']);
        CommunicationRecipient::create(['communication_id' => $communication->id, 'user_id' => $guardian->id, 'student_id' => $student->id]);
        $this->login($tutor);
        $this->get(route('communications.create', ['student_dni' => $student->dni]))->assertOk()->assertViewHas('prefillDni', $student->dni);
        $this->get(route('communications.create', ['student_dni' => $other->dni]))->assertForbidden();
        $this->get(route('communications.create', ['student_dni' => 'invalid']))->assertSessionHasErrors('student_dni');
        $this->get(route('communications.receipts', $communication))->assertOk()->assertSee($guardian->name);
        $this->get(route('dashboard'))->assertOk()->assertSee('Cita de seguimiento');
        DB::table('tutor_assignments')->where('user_id', $tutor->id)->update(['is_active' => false]);
        $this->get(route('communications.receipts', $communication))->assertForbidden();
        $this->get(route('communications.index'))->assertOk()->assertDontSee('Cita de seguimiento');
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Cita de seguimiento');
    }

    public function test_justification_filters_and_revoked_permissions_hide_sensitive_counts(): void
    {
        [$tutor,$course,,$enrollment] = $this->section();
        $pending = AbsenceJustification::factory()->create(['course_attendance_record_id' => $this->record($course, $enrollment, 'unjustified_absence')->id]);
        $approved = AbsenceJustification::factory()->create(['course_attendance_record_id' => $this->record($course, $enrollment, 'justified_absence', '2026-10-26')->id, 'status' => 'approved']);
        $this->login($tutor);
        $this->get(route('justifications.index'))->assertOk()->assertViewHas('requests', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $pending->id);
        $this->get(route('justifications.index', ['status' => 'approved']))->assertOk()->assertViewHas('requests', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $approved->id);
        $this->get(route('justifications.index', ['status' => 'all']))->assertOk()->assertViewHas('requests', fn ($rows) => $rows->count() === 2);
        $this->get(route('justifications.index', ['status' => 'invalid']))->assertSessionHasErrors('status');
        Role::where('name', 'tutor')->firstOrFail()->permissions()->detach(DB::table('permissions')->whereIn('name', ['attendance.view', 'attendance.record'])->pluck('id'));
        $this->login($tutor);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['registered_today'] === 0 && $stats['pending_justifications'] === 0);
        $this->get(route('justifications.index'))->assertForbidden();
    }

    private function section(): array
    {
        $course = CourseOffering::factory()->create();
        $student = Student::factory()->create();
        $enrollment = Enrollment::create(['student_id' => $student->id, 'school_year_id' => $course->section->school_year_id, 'section_id' => $course->section_id, 'starts_on' => '2026-03-02']);
        $tutor = $this->user(RoleName::Tutor);
        DB::table('tutor_assignments')->insert(['user_id' => $tutor->id, 'section_id' => $course->section_id, 'is_active' => true]);

        return [$tutor, $course, $student, $enrollment];
    }

    private function user(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->firstOrFail());

        return $user;
    }

    private function login(User $user): void
    {
        $user->refresh()->unsetRelation('roles');
        $this->actingAs($user)->withSession(['active_role' => $user->defaultRole()->value, 'session_version' => $user->session_version, 'last_activity_at' => now()->timestamp]);
    }

    private function record(CourseOffering $course, Enrollment $enrollment, string $status, string $date = '2026-10-27'): CourseAttendanceRecord
    {
        $day = SchoolDay::where('school_year_id', $enrollment->school_year_id)->whereDate('date', $date)->first() ?? SchoolDay::create(['school_year_id' => $enrollment->school_year_id, 'date' => $date, 'is_instructional' => true]);
        $session = CourseAttendanceSession::factory()->create(['course_offering_id' => $course->id, 'school_day_id' => $day->id]);

        return CourseAttendanceRecord::factory()->create(['course_attendance_session_id' => $session->id, 'enrollment_id' => $enrollment->id, 'status' => $status, 'arrival_time' => $status === 'present' ? '08:00:00' : null]);
    }
}
