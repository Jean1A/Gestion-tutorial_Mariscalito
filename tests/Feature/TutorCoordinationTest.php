<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AbsenceJustification;
use App\Models\Communication;
use App\Models\CourseAttendanceAlert;
use App\Models\CourseAttendanceRecord;
use App\Models\CourseAttendanceSession;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SchoolDay;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ConsentService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TutorCoordinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
    }

    public function test_administrator_and_coordinator_have_working_coordination_pages_and_school_navigation(): void
    {
        [$tutor] = $this->schoolSection();
        foreach ([RoleName::Administrator, RoleName::Coordinator] as $role) {
            $this->login($this->user($role));
            $this->get(route('dashboard'))->assertOk()->assertViewIs('coordination.home')->assertSee('Gestionar asignaciones');
            foreach (['index', 'tutors', 'assignments', 'activities', 'follow-up'] as $page) {
                $this->get(route('coordination.'.$page))->assertOk()
                    ->assertSee($role === RoleName::Coordinator ? 'Coordinación de tutoría' : 'Portal del administrador');
            }
            $this->get(route('coordination.tutor', $tutor))->assertOk()->assertSee($tutor->name);
        }
        $this->login($this->user(RoleName::Administrator));
        $this->get(route('users.index'))->assertOk()->assertSee('Equipo de tutores')->assertSee('Gestión del colegio');
    }

    public function test_other_roles_direct_urls_role_switch_and_revoked_permissions_are_denied(): void
    {
        [$tutor, $offering] = $this->schoolSection();
        foreach ([RoleName::Tutor, RoleName::Teacher, RoleName::Guardian, RoleName::Assistant, RoleName::Student] as $role) {
            $this->login($this->user($role));
            foreach (['index', 'tutors', 'assignments', 'activities', 'follow-up'] as $page) {
                $this->get(route('coordination.'.$page))->assertForbidden();
            }
            $this->get(route('coordination.tutor', $tutor))->assertForbidden();
            $this->put(route('coordination.assignments.save', $offering->section_id), ['tutor_id' => $tutor->id, 'is_active' => true])->assertForbidden();
        }
        $coordinator = $this->user(RoleName::Coordinator);
        $coordinator->roles()->attach(Role::where('name', 'tutor')->firstOrFail());
        $this->login($coordinator);
        $this->get(route('coordination.index'))->assertOk();
        $this->post(route('role.switch'), ['role' => 'tutor'])->assertRedirect();
        $this->get(route('coordination.index'))->assertForbidden();
        $this->post(route('role.switch'), ['role' => 'coordinator'])->assertRedirect();
        Role::where('name', 'coordinator')->firstOrFail()->permissions()->detach(Permission::where('name', 'tutors.coordinate')->value('id'));
        $this->get(route('coordination.index'))->assertForbidden();
        auth()->logout();
        $this->get(route('coordination.index'))->assertRedirect(route('login'));
    }

    public function test_dashboard_deduplicates_students_and_attributes_work_to_the_actual_actor(): void
    {
        [$tutor, $offering, $student, $enrollment] = $this->schoolSection();
        $secondTutor = $this->user(RoleName::Tutor);
        $secondTutor->tutoredSections()->attach($offering->section_id, ['is_active' => true]);
        $first = $this->record($offering, $enrollment, $tutor, '2026-10-29', 'unjustified_absence');
        $second = $this->record($offering, $enrollment, $offering->teacher, '2026-10-30', 'present');
        AbsenceJustification::factory()->create(['course_attendance_record_id' => $first->id, 'status' => 'approved', 'reviewed_by' => $tutor->id, 'reviewed_at' => now()]);
        AbsenceJustification::factory()->create(['course_attendance_record_id' => $second->id]);
        foreach ([10, 20] as $threshold) {
            CourseAttendanceAlert::factory()->create(['course_offering_id' => $offering->id, 'enrollment_id' => $enrollment->id, 'threshold' => $threshold]);
        }
        $this->communication($tutor, $offering->section->school_year_id);
        $this->login($this->user(RoleName::Administrator));
        $this->get(route('coordination.index'))->assertOk()->assertViewHas('stats', fn (array $stats) => $stats['tutors'] === 2 && $stats['students'] === 1 && $stats['sections'] === 1 && $stats['assigned_sections'] === 1 && $stats['registered'] === 2 && $stats['sessions'] === 2 && $stats['absence_percentage'] === 50.0 && $stats['pending'] === 1 && $stats['risk_students'] === 1 && $stats['reviewed'] === 1 && $stats['appointments'] === 1)
            ->assertViewHas('tutorRows', fn ($rows) => $rows->firstWhere('id', $tutor->id)->coordination_metrics['sessions'] === 1 && $rows->firstWhere('id', $secondTutor->id)->coordination_metrics['sessions'] === 0);
        $this->get(route('coordination.follow-up'))->assertOk()->assertViewHas('alerts', fn ($rows) => $rows->total() === 1)->assertSee($student->full_name);
    }

    public function test_tutor_and_date_filters_isolate_attendance_activity_and_communication(): void
    {
        [$tutor, $offering, , $enrollment] = $this->schoolSection();
        [$otherTutor, $otherOffering, , $otherEnrollment] = $this->schoolSection();
        $this->record($offering, $enrollment, $tutor, '2026-10-29', 'present');
        $this->record($offering, $enrollment, $tutor, '2026-10-30', 'unjustified_absence');
        $this->record($otherOffering, $otherEnrollment, $otherTutor, '2026-10-30', 'present');
        $this->communication($tutor, $offering->section->school_year_id);
        $this->communication($otherTutor, $offering->section->school_year_id);
        $this->login($this->user(RoleName::Coordinator));
        $filters = ['tutor_id' => $tutor->id, 'from' => '2026-10-30', 'to' => '2026-10-30'];
        $this->get(route('coordination.index', $filters))->assertOk()->assertViewHas('stats', fn (array $stats) => $stats['sessions'] === 1 && $stats['registered'] === 1 && $stats['communications'] === 1 && $stats['absence_percentage'] === 100.0);
        $this->get(route('coordination.activities', $filters))->assertOk()->assertViewHas('sessions', fn ($rows) => $rows->total() === 1)->assertViewHas('communications', fn ($rows) => $rows->total() === 1);
        $this->get(route('coordination.tutor', ['tutor' => $tutor, 'tutor_id' => $otherTutor->id]))->assertOk()->assertViewHas('filters', fn (array $values) => $values['tutor_id'] === $tutor->id);
    }

    public function test_reassignment_is_audited_preserves_history_and_revokes_previous_tutor_scope(): void
    {
        [$previousTutor, $offering, $student, $enrollment] = $this->schoolSection();
        $record = $this->record($offering, $enrollment, $previousTutor, '2026-10-30', 'present');
        $replacement = $this->user(RoleName::Tutor);
        $coordinator = $this->user(RoleName::Coordinator);
        $this->login($coordinator);
        $url = route('coordination.assignments.save', $offering->section_id);
        $this->put($url, ['tutor_id' => $replacement->id, 'is_active' => true])->assertRedirect(route('coordination.assignments', ['school_year_id' => $offering->section->school_year_id]));
        $this->put($url, ['tutor_id' => $replacement->id, 'is_active' => true])->assertRedirect();
        $this->assertDatabaseHas('tutor_assignments', ['user_id' => $previousTutor->id, 'section_id' => $offering->section_id, 'is_active' => false]);
        $this->assertDatabaseHas('tutor_assignments', ['user_id' => $replacement->id, 'section_id' => $offering->section_id, 'is_active' => true]);
        $this->assertSame(2, DB::table('tutor_assignments')->where('section_id', $offering->section_id)->count());
        $this->assertDatabaseHas('course_attendance_records', ['id' => $record->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'coordination.tutor_assignment_saved', 'user_id' => $coordinator->id, 'subject_id' => $offering->section_id]);
        $this->login($previousTutor);
        $this->get(route('advisor.student', $student))->assertForbidden();
        $this->login($replacement);
        $this->get(route('advisor.student', $student))->assertOk();
        $this->login($coordinator);
        $this->put($url, ['tutor_id' => $replacement->id, 'is_active' => false])->assertRedirect();
        $this->get(route('coordination.index'))->assertOk()->assertViewHas('stats', fn (array $stats) => $stats['unassigned_sections'] === 1);
    }

    public function test_assignment_rejects_inactive_accounts_wrong_roles_and_inactive_structure_atomically(): void
    {
        [$tutor, $offering] = $this->schoolSection();
        $inactive = $this->user(RoleName::Tutor);
        $inactive->update(['is_active' => false]);
        $teacher = $this->user(RoleName::Teacher);
        $this->login($this->user(RoleName::Administrator));
        $url = route('coordination.assignments.save', $offering->section_id);
        foreach ([$inactive->id, $teacher->id, 999999] as $id) {
            $this->put($url, ['tutor_id' => $id, 'is_active' => true])->assertSessionHasErrors('tutor_id');
        }
        $offering->section->update(['is_active' => false]);
        $this->put($url, ['tutor_id' => $tutor->id, 'is_active' => true])->assertSessionHasErrors('tutor_id');
        $this->put($url, ['tutor_id' => $teacher->id, 'is_active' => false])->assertSessionHasErrors('tutor_id');
        $this->assertDatabaseHas('tutor_assignments', ['section_id' => $offering->section_id, 'user_id' => $tutor->id, 'is_active' => true]);
    }

    public function test_filters_reject_wrong_year_sections_non_tutors_and_invalid_date_ranges(): void
    {
        [$tutor, $offering] = $this->schoolSection();
        $year = SchoolYear::create(['year' => 2025, 'starts_on' => '2025-03-03', 'ends_on' => '2025-12-19', 'is_active' => false]);
        $this->login($this->user(RoleName::Coordinator));
        foreach ([['from' => '2026-11-01', 'to' => '2026-10-01'], ['from' => '2025-01-01'], ['from' => 'invalid'], ['school_year_id' => $year->id, 'section_id' => $offering->section_id], ['tutor_id' => $offering->teacher_id], ['q' => str_repeat('a', 101)], ['status' => 'invalid']] as $filters) {
            $this->get(route('coordination.index', $filters))->assertSessionHasErrors();
        }
        $this->get(route('coordination.tutor', $offering->teacher_id))->assertNotFound();
        $this->get(route('coordination.index', ['school_year_id' => $year->id]))->assertOk()->assertViewHas('stats', fn (array $stats) => $stats['sessions'] === 0 && $stats['students'] === 0);
    }

    public function test_follow_up_displays_status_without_exposing_private_sustento_or_approval_controls(): void
    {
        [$tutor, $offering, $student, $enrollment] = $this->schoolSection();
        $record = $this->record($offering, $enrollment, $tutor, '2026-10-30', 'unjustified_absence');
        $justification = AbsenceJustification::factory()->create(['course_attendance_record_id' => $record->id, 'description' => 'Información médica privada de prueba', 'path' => 'justifications/documento-privado.pdf', 'review_note' => 'Observación confidencial de prueba']);
        $this->login($this->user(RoleName::Administrator));
        $this->get(route('coordination.follow-up'))->assertOk()->assertSee($student->full_name)->assertDontSee($justification->description)->assertDontSee($justification->path)->assertDontSee($justification->review_note)->assertDontSee('Aprobar solicitud');
        $this->get(route('justifications.document', $justification))->assertForbidden();
        $this->post(route('justifications.review', $justification), ['decision' => 'approved', 'review_note' => 'Validación de prueba'])->assertForbidden();
    }

    public function test_activity_feed_is_scoped_and_never_displays_raw_audit_changes(): void
    {
        [$tutor, $offering, , $enrollment] = $this->schoolSection();
        [$otherTutor, $otherOffering, , $otherEnrollment] = $this->schoolSection();
        $record = $this->record($offering, $enrollment, $tutor, '2026-10-30', 'present');
        $other = $this->record($otherOffering, $otherEnrollment, $otherTutor, '2026-10-30', 'present');
        app(AuditService::class)->record('course.attendance_saved', $record, ['private' => 'Datos sensibles que no se muestran'], $tutor->id);
        app(AuditService::class)->record('course.attendance_saved', $other, [], $otherTutor->id);
        app(AuditService::class)->record('auth.login', $tutor, [], $tutor->id);
        $this->login($this->user(RoleName::Coordinator));
        $this->get(route('coordination.activities', ['tutor_id' => $tutor->id]))->assertOk()->assertViewHas('activities', fn ($rows) => $rows->total() === 1 && $rows->first()->tutor_id === $tutor->id)->assertDontSee('Datos sensibles que no se muestran');
        $this->get(route('coordination.activities', ['section_id' => $otherOffering->section_id]))->assertOk()->assertViewHas('activities', fn ($rows) => $rows->total() === 1 && $rows->first()->tutor_id === $otherTutor->id);
    }

    public function test_empty_states_and_directory_escape_content_and_support_inactive_tutors(): void
    {
        $this->login($this->user(RoleName::Administrator));
        $this->get(route('coordination.index'))->assertOk()->assertSee('Configura un año escolar')->assertViewHas('stats', fn (array $stats) => $stats['coverage'] === 0 && $stats['sessions'] === 0);
        [$tutor] = $this->schoolSection();
        $tutor->update(['name' => '<script>alert(1)</script>', 'is_active' => false]);
        $this->get(route('coordination.tutors', ['q' => $tutor->dni, 'account_status' => 'inactive']))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertViewHas('tutorRows', fn ($rows) => $rows->total() === 1);
        $this->get(route('coordination.index'))->assertOk()->assertViewHas('stats', fn (array $stats) => $stats['unassigned_sections'] === 1);
        $this->get(route('coordination.tutor', $tutor))->assertOk()->assertSee('Cuenta inactiva');
    }

    public function test_sessions_are_sorted_by_date_even_when_calendar_ids_are_out_of_order(): void
    {
        [$tutor, $offering, , $enrollment] = $this->schoolSection();
        $latest = $this->record($offering, $enrollment, $tutor, '2026-10-30', 'present');
        $this->record($offering, $enrollment, $tutor, '2026-10-29', 'present');
        $this->login($this->user(RoleName::Coordinator));
        $this->get(route('coordination.activities'))->assertOk()->assertViewHas('sessions', fn ($sessions) => $sessions->first()->id === $latest->course_attendance_session_id && $sessions->first()->records_count === 1);
        foreach ([['to' => '2026-10-29'], ['from' => '', 'to' => '2026-10-29'], ['from' => '2026-10-30']] as $filters) {
            $this->get(route('coordination.index', $filters))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['sessions'] === 1);
        }
    }

    public function test_legacy_demo_coordinator_signs_into_the_same_portal_as_the_current_coordinator(): void
    {
        $obsoleteRoleId = DB::table('roles')->insertGetId(['name' => 'director', 'label' => 'Dirección anterior']);
        $legacy = User::factory()->create(['dni' => '90000011', 'email' => 'coordinator-preview@example.test']);
        DB::table('role_user')->insert(['role_id' => $obsoleteRoleId, 'user_id' => $legacy->id]);
        $current = $this->user(RoleName::Coordinator);
        $current->update(['dni' => '90000012']);
        $passwordHash = $legacy->password;
        $sessionVersion = $legacy->fresh()->session_version;
        $unrelated = User::factory()->create();
        DB::table('role_user')->insert(['role_id' => $obsoleteRoleId, 'user_id' => $unrelated->id]);
        $guardian = $this->user(RoleName::Guardian);
        $guardian->update(['dni' => '90000009', 'email' => 'portal-preview@example.test']);
        $guardian->roles()->attach(Role::where('name', 'coordinator')->value('id'));
        $retired = User::factory()->create(['dni' => '90000003', 'email' => 'toe_committee@example.test']);

        $this->actingAs($legacy)->withSession(['active_role' => 'director', 'session_version' => $sessionVersion, 'last_activity_at' => now()->timestamp]);

        $migration = require database_path('migrations/2026_11_08_193843_reconcile_legacy_demo_account_roles.php');
        $migration->up();
        $migration->up();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertSame($passwordHash, $legacy->fresh()->password);
        $this->assertSame($sessionVersion + 1, $legacy->fresh()->session_version);
        $this->assertSame([RoleName::Coordinator], $legacy->fresh()->roles->pluck('name')->all());
        $this->assertDatabaseHas('role_user', ['role_id' => $obsoleteRoleId, 'user_id' => $unrelated->id]);
        $this->assertSame([RoleName::Guardian], $guardian->fresh()->roles->pluck('name')->all());
        $this->assertFalse($retired->fresh()->is_active);

        foreach ([$legacy, $current] as $coordinator) {
            $this->post(route('login.store'), ['dni' => $coordinator->dni, 'password' => 'password'])
                ->assertRedirect(route('dashboard'))->assertSessionHas('active_role', 'coordinator');
            $this->get(route('dashboard'))->assertOk()->assertViewIs('coordination.home')
                ->assertSee('Gestión de usuarios')->assertSee('Estructura académica');
            $this->get(route('users.index'))->assertOk();
            $this->get(route('settings.index'))->assertForbidden();
            $this->post(route('logout'))->assertRedirect(route('login'));
        }
    }

    public function test_demo_seeding_keeps_dni_roles_stable_and_does_not_add_a_second_tutor(): void
    {
        $guardian = $this->user(RoleName::Guardian);
        $guardian->update(['dni' => '90000009', 'email' => 'portal-preview@example.test']);
        $repository = Env::getRepository();
        $oldPassword = $repository->get('DEMO_PASSWORD');
        $repository->set('DEMO_PASSWORD', 'DemoTestPassword2026');
        try {
            $this->seed(DemoSeeder::class);
            $this->seed(DemoSeeder::class);
        } finally {
            if ($oldPassword === null) {
                $repository->clear('DEMO_PASSWORD');
            } else {
                $repository->set('DEMO_PASSWORD', $oldPassword);
            }
        }

        $this->assertSame([RoleName::Guardian], $guardian->fresh()->roles->pluck('name')->all());
        $this->assertDatabaseMissing('users', ['dni' => '90000003']);
        $tutor = User::where('dni', '90000004')->firstOrFail();
        $this->assertSame([$tutor->id], DB::table('tutor_assignments')->where('is_active', true)->pluck('user_id')->all());
    }

    public function test_local_coordinator_credentials_work_and_repeating_the_command_keeps_the_password(): void
    {
        Storage::fake('local');
        $this->artisan('school:create-coordinator-demo')->assertSuccessful();
        $credentials = Storage::disk('local')->get('coordinator-role-access.txt');
        preg_match('/Contraseña temporal: (.+)/u', $credentials, $matches);
        $user = User::where('dni', '90000012')->firstOrFail();
        $passwordHash = $user->password;
        $this->post(route('login.store'), ['dni' => $user->dni, 'password' => $matches[1]])->assertRedirect(route('password.change'));
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
        $this->assertTrue($user->hasRole(RoleName::Coordinator));
        $this->assertTrue($user->must_change_password);
        $this->artisan('school:create-coordinator-demo')->assertFailed();
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertSame($credentials, Storage::disk('local')->get('coordinator-role-access.txt'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'coordination.demo_account_created', 'subject_id' => $user->id]);
    }

    public function test_demo_command_is_rejected_in_production_without_creating_an_account(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('school:create-coordinator-demo')->assertFailed();
        $this->assertDatabaseMissing('users', ['dni' => '90000012']);
    }

    /** @return array{User, CourseOffering, Student, Enrollment} */
    private function schoolSection(): array
    {
        $offering = CourseOffering::factory()->create();
        $tutor = $this->user(RoleName::Tutor);
        $tutor->tutoredSections()->attach($offering->section_id, ['is_active' => true]);
        $student = Student::factory()->create();
        $enrollment = Enrollment::create(['student_id' => $student->id, 'school_year_id' => $offering->section->school_year_id, 'section_id' => $offering->section_id, 'starts_on' => '2026-03-02']);

        return [$tutor, $offering, $student, $enrollment];
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
        if ($user->hasRole(RoleName::Guardian)) {
            app(ConsentService::class)->accept($user);
        }
        $this->actingAs($user)->withSession(['active_role' => $user->defaultRole()->value, 'session_version' => $user->session_version, 'last_activity_at' => now()->timestamp]);
    }

    private function record(CourseOffering $offering, Enrollment $enrollment, User $actor, string $date, string $status): CourseAttendanceRecord
    {
        $day = SchoolDay::where('school_year_id', $offering->section->school_year_id)->whereDate('date', $date)->first() ?? SchoolDay::create(['school_year_id' => $offering->section->school_year_id, 'date' => $date, 'is_instructional' => true]);
        $session = CourseAttendanceSession::factory()->create(['course_offering_id' => $offering->id, 'school_day_id' => $day->id, 'recorded_by' => $actor->id]);

        return CourseAttendanceRecord::factory()->create(['course_attendance_session_id' => $session->id, 'enrollment_id' => $enrollment->id, 'status' => $status]);
    }

    private function communication(User $tutor, int $yearId): Communication
    {
        return Communication::create(['user_id' => $tutor->id, 'school_year_id' => $yearId, 'type' => 'appointment', 'title' => 'Reunión de seguimiento', 'body' => 'Coordinación con la familia.', 'scheduled_at' => now()->addDay(), 'location' => 'Sala de tutoría']);
    }
}
