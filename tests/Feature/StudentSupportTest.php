<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AcademicPeriod;
use App\Models\CourseAttendanceRecord;
use App\Models\CourseAttendanceSession;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\FamilyMessage;
use App\Models\Role;
use App\Models\SchoolDay;
use App\Models\Student;
use App\Models\SupportRecord;
use App\Models\TutoringSession;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\StudentService;
use App\Services\StudentSupportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentSupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
        Queue::fake();
    }

    private function user(RoleName $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', $role->value)->firstOrFail());

        return $u;
    }

    private function login(User $u): void
    {
        $u->refresh()->unsetRelation('roles');
        $this->actingAs($u)->withSession(['active_role' => $u->defaultRole()->value, 'session_version' => $u->session_version, 'last_activity_at' => now()->timestamp]);
    }

    private function school(): array
    {
        $course = CourseOffering::factory()->create();
        $tutor = $this->user(RoleName::Tutor);
        $tutor->tutoredSections()->attach($course->section_id, ['is_active' => true]);
        $student = Student::factory()->create();
        $enrollment = Enrollment::create(['student_id' => $student->id, 'section_id' => $course->section_id, 'school_year_id' => $course->section->school_year_id, 'starts_on' => '2026-03-02']);

        return [$tutor, $course, $student, $enrollment];
    }

    private function guardian(Student $student, User $tutor): User
    {
        $u = $this->user(RoleName::Guardian);
        $u->students()->attach($student, ['relationship' => 'Apoderado', 'is_active' => true, 'verified_by' => $tutor->id, 'verified_at' => now()]);
        app(ConsentService::class)->accept($u);

        return $u;
    }

    private function payload(CourseOffering $course, Student $student): array
    {
        return ['student_id' => $student->id, 'section_id' => $course->section_id, 'kind' => 'interview', 'title' => 'Entrevista de acompañamiento', 'description' => 'Motivo de la atención y antecedentes escolares.', 'agreements' => 'Acuerdo con responsables y fechas.', 'visibility' => 'reserved', 'severity' => 'low', 'status' => 'open', 'occurred_on' => '2026-10-29', 'revision' => 0];
    }

    public function test_tutor_creates_updates_follows_and_closes_with_revision_and_scope(): void
    {
        [$tutor,$course,$student] = $this->school();
        [,$foreign,$other] = $this->school();
        $this->login($tutor);
        $this->get(route('support.index'))->assertOk();
        $this->get(route('support.create'))->assertOk();
        $data = $this->payload($course, $student);
        $this->post(route('support.store'), $data)->assertRedirect();
        $r = SupportRecord::firstOrFail();
        $this->get(route('support.show', $r))->assertOk()->assertSee($data['description']);
        $this->get(route('support.edit', $r))->assertOk();
        $this->post(route('support.follow-up', $r), ['note' => 'La familia cumplió el acuerdo de esta semana.', 'next_on' => '2026-11-02'])->assertRedirect();
        $this->assertSame('following', $r->fresh()->status);
        $this->assertDatabaseCount('support_follow_ups', 1);
        $this->put(route('support.update', $r), array_replace($data, ['revision' => 1, 'status' => 'closed', 'closure_note' => 'Se alcanzaron los acuerdos.']))->assertSessionHasErrors('revision');
        $this->put(route('support.update', $r), array_replace($data, ['revision' => 2, 'status' => 'closed']))->assertSessionHasErrors('closure_note');
        $this->put(route('support.update', $r), array_replace($data, ['revision' => 2, 'status' => 'closed', 'closure_note' => 'Se alcanzaron los acuerdos.']))->assertRedirect();
        $this->post(route('support.follow-up', $r), ['note' => 'Intento de modificación posterior al cierre.'])->assertSessionHasErrors('note');
        $this->post(route('support.store'), $this->payload($foreign, $other))->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.record_saved', 'subject_id' => $r->id]);
    }

    public function test_tutor_routes_a_reserved_case_to_coordination_without_exposing_it_to_other_roles(): void
    {
        [$tutor,$course,$student] = $this->school();
        $coordinator = $this->user(RoleName::Coordinator);
        $guardian = $this->guardian($student, $tutor);
        $this->login($tutor);
        $this->post(route('support.store'), array_replace($this->payload($course, $student), ['kind' => 'case', 'visibility' => 'shared']))
            ->assertRedirect(route('support.index'))->assertSessionHas('status', 'Caso reservado remitido a Coordinación de tutoría.');
        $r = SupportRecord::firstOrFail();
        $this->assertSame('reserved', $r->visibility);
        foreach ([$tutor, $guardian, $this->user(RoleName::Teacher), $this->user(RoleName::Assistant)] as $u) {
            $this->login($u);
            $this->get(route('support.show', $r))->assertForbidden();
        }
        $this->login($coordinator);
        $this->get(route('support.show', $r))->assertOk()->assertSee($r->description);
        $this->get(route('support.edit', $r))->assertOk();
        $this->post(route('support.follow-up', $r), ['note' => 'Atención reservada registrada.'])->assertRedirect();
        $this->get(route('support.show', $r))->assertOk()->assertSee('Atención reservada registrada.');
        $this->put(route('support.update', $r), array_replace($this->payload($course, $student), [
            'kind' => 'case', 'revision' => $r->fresh()->revision, 'status' => 'closed', 'closure_note' => 'Seguimiento finalizado.',
        ]))->assertRedirect(route('support.show', $r));
        $this->assertSame('closed', $r->fresh()->status);
    }

    public function test_shared_records_visible_only_to_linked_family_and_auxiliary_cannot_read_reserved(): void
    {
        [$tutor,$course,$student] = $this->school();
        $guardian = $this->guardian($student, $tutor);
        $other = $this->user(RoleName::Guardian);
        app(ConsentService::class)->accept($other);
        $shared = SupportRecord::factory()->create(array_replace($this->payload($course, $student), ['author_id' => $tutor->id, 'visibility' => 'shared', 'family_summary' => 'Acuerdo publicado para la familia.', 'revision' => 1]));
        $reserved = SupportRecord::factory()->create(array_replace($this->payload($course, $student), ['author_id' => $tutor->id, 'revision' => 1, 'title' => 'Información reservada']));
        $this->login($guardian);
        $this->get(route('support.index'))->assertOk()->assertSee('Entrevista #'.$shared->id)->assertDontSee($reserved->title);
        $this->get(route('support.show', $shared))->assertOk()->assertSee($shared->family_summary)->assertDontSee($shared->description)->assertDontSee($shared->agreements);
        $this->get(route('support.show', $reserved))->assertForbidden();
        $this->get(route('support.edit', $shared))->assertForbidden();
        $this->login($other);
        $this->get(route('support.show', $shared))->assertForbidden();
        $aux = $this->user(RoleName::Assistant);
        DB::table('assistant_assignments')->insert(['user_id' => $aux->id, 'grade_id' => $course->section->grade_id, 'school_year_id' => $course->section->school_year_id, 'is_active' => true]);
        $this->login($aux);
        $this->get(route('support.show', $shared))->assertForbidden();
        $this->get(route('support.show', $reserved))->assertForbidden();
        $this->post(route('support.store'), array_replace($this->payload($course, $student), ['kind' => 'incident', 'visibility' => 'internal']))->assertRedirect();
        $this->post(route('support.store'), $this->payload($course, $student))->assertForbidden();
    }

    public function test_profile_is_year_scoped_reserved_and_concurrency_protected(): void
    {
        [$tutor,$course,$student] = $this->school();
        [,$otherCourse,$other] = $this->school();
        $year = $course->section->school_year_id;
        $this->login($tutor);
        $url = route('support.profile', ['student' => $student, 'school_year_id' => $year]);
        $this->get($url)->assertOk();
        $this->put($url, ['family_context' => 'Contexto privado de la familia.', 'revision' => 0])->assertRedirect();
        $this->put($url, ['family_context' => 'Sobrescritura obsoleta.', 'revision' => 0])->assertSessionHasErrors('revision');
        $this->get(route('support.profile', ['student' => $other, 'school_year_id' => $year]))->assertForbidden();
        $g = $this->guardian($student, $tutor);
        $this->login($g);
        $this->get($url)->assertForbidden();
        $this->assertDatabaseHas('support_profiles', ['family_context' => 'Contexto privado de la familia.']);
    }

    public function test_group_sessions_validate_roster_revision_results_and_private_evidence(): void
    {
        Storage::fake('local');
        [$tutor,$course,$student] = $this->school();
        [,, $other] = $this->school();
        $this->login($tutor);
        $this->get(route('support.sessions'))->assertOk();
        $data = ['section_id' => $course->section_id, 'topic' => 'Convivencia', 'objectives' => 'Construir acuerdos de aula.', 'scheduled_on' => '2026-10-29', 'status' => 'completed', 'outcomes' => 'Acuerdos registrados por la sección.', 'attendees' => [$student->id], 'revision' => 0, 'evidence' => UploadedFile::fake()->create('evidencia.pdf', 20, 'application/pdf')];
        $this->post(route('support.sessions.store'), array_replace($data, ['attendees' => [$other->id]]))->assertSessionHasErrors('attendees');
        $this->post(route('support.sessions.store'), $data)->assertRedirect();
        $s = TutoringSession::firstOrFail();
        Storage::disk('local')->assertExists($s->evidence_path);
        $this->get(route('support.sessions.evidence', $s))->assertOk();
        $this->put(route('support.sessions.update', $s), array_replace($data, ['revision' => 0]))->assertSessionHasErrors('revision');
        $g = $this->guardian($student, $tutor);
        $this->login($g);
        $this->get(route('support.sessions.evidence', $s))->assertForbidden();
    }

    public function test_messages_are_private_mark_read_and_revalidate_assignment(): void
    {
        [$tutor,$course,$student] = $this->school();
        $g = $this->guardian($student, $tutor);
        $other = $this->guardian($student, $tutor);
        $params = ['student_id' => $student->id, 'tutor_id' => $tutor->id, 'guardian_id' => $g->id];
        $this->login($g);
        $this->post(route('support.messages', $params), ['body' => 'Consulta privada de la familia.'])->assertRedirect();
        $this->get(route('support.messages', $params))->assertOk()->assertSee('Consulta privada de la familia.');
        $this->login($other);
        $this->get(route('support.messages', $params))->assertForbidden();
        $this->login($tutor);
        $this->get(route('support.messages', $params))->assertOk();
        $this->assertNotNull(FamilyMessage::firstOrFail()->read_at);
        $tutor->tutoredSections()->updateExistingPivot($course->section_id, ['is_active' => false]);
        $this->login($g);
        $this->get(route('support.messages', $params))->assertForbidden();
    }

    public function test_recovery_requires_course_in_section_and_risk_uses_multiple_dimensions(): void
    {
        [$tutor,$course,$student,$enrollment] = $this->school();
        [,$other] = $this->school();
        $this->login($tutor);
        $data = array_replace($this->payload($course, $student), ['kind' => 'recovery', 'course_offering_id' => $other->id]);
        $this->post(route('support.store'), $data)->assertSessionHasErrors('course_offering_id');
        $this->post(route('support.store'), array_replace($data, ['course_offering_id' => $course->id]))->assertRedirect();
        $this->assertSame('green', app(StudentSupportService::class)->risk($student, $course->section->school_year_id)['level']);
        SupportRecord::factory()->create(array_replace($this->payload($course, $student), ['author_id' => $tutor->id, 'kind' => 'incident', 'severity' => 'high', 'revision' => 1]));
        $this->assertSame('red', app(StudentSupportService::class)->risk($student, $course->section->school_year_id)['level']);
    }

    public function test_reports_export_only_for_coordinator_without_private_notes(): void
    {
        [$tutor,$course,$student] = $this->school();
        SupportRecord::factory()->create(array_replace($this->payload($course, $student), ['author_id' => $tutor->id, 'description' => 'Nota psicológica privada que no se exporta.', 'revision' => 1]));
        $this->login($this->user(RoleName::Coordinator));
        foreach (['attendance', 'interventions', 'sessions', 'risk', 'siseve'] as $type) {
            $this->get(route('school-reports.index', ['type' => $type]))->assertOk()->assertDontSee('Nota psicológica privada que no se exporta.');
        }
        $this->get(route('school-reports.export', ['format' => 'pdf', 'type' => 'interventions']))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDontSee('Nota psicológica privada que no se exporta.');
        $this->get(route('school-reports.export', ['format' => 'xlsx', 'type' => 'interventions']))->assertOk()->assertDownload('reporte-interventions.xlsx');
        $this->get(route('school-reports.export', ['format' => 'zip']))->assertNotFound();
        $this->login($tutor);
        $this->get(route('school-reports.index'))->assertForbidden();
    }

    public function test_role_cleanup_revokes_old_sessions_and_preserves_valid_roles_and_case_history(): void
    {
        [$tutor, $course, $student] = $this->school();
        $legacy = User::factory()->create(['password' => 'ExamplePass1234']);
        $unassigned = User::factory()->create();
        $mixed = $this->user(RoleName::Tutor);
        $obsoleteIds = [];
        foreach (['director', 'psychologist', 'toe_committee'] as $name) {
            $obsoleteIds[$name] = DB::table('roles')->insertGetId(['name' => $name, 'label' => 'Perfil antiguo']);
        }
        DB::table('role_user')->insert([
            ['role_id' => $obsoleteIds['psychologist'], 'user_id' => $legacy->id],
            ['role_id' => $obsoleteIds['director'], 'user_id' => $mixed->id],
        ]);
        DB::table('permission_role')->insert(['role_id' => $obsoleteIds['director'], 'permission_id' => DB::table('permissions')->where('name', 'students.view')->value('id')]);
        $record = SupportRecord::factory()->create(array_replace($this->payload($course, $student), ['author_id' => $tutor->id, 'kind' => 'case']));
        DB::table('support_records')->where('id', $record->id)->update(['assigned_to' => $legacy->id]);
        $this->login($mixed);
        $version = $mixed->session_version;
        $migration = require database_path('migrations/2026_11_09_230838_remove_unsupported_school_roles.php');
        $migration->up();
        $migration->up();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertDatabaseCount('roles', 7);
        $this->assertDatabaseMissing('permission_role', ['role_id' => $obsoleteIds['director']]);
        $this->assertDatabaseMissing('role_user', ['role_id' => $obsoleteIds['psychologist']]);
        $this->assertSame($version + 1, $mixed->fresh()->session_version);
        $this->assertSame([RoleName::Tutor], $mixed->fresh()->roles->pluck('name')->all());
        $this->assertTrue($mixed->fresh()->is_active);
        $this->assertFalse($legacy->fresh()->is_active);
        $this->assertFalse($unassigned->fresh()->is_active);
        $this->assertDatabaseHas('support_records', ['id' => $record->id, 'assigned_to' => $legacy->id]);
        $this->post(route('login.store'), ['dni' => $legacy->dni, 'password' => 'ExamplePass1234'])->assertSessionHasErrors('dni');
        $this->login($this->user(RoleName::Coordinator));
        $this->get(route('support.show', $record))->assertOk()->assertSee($record->description);
        $this->login($this->user(RoleName::Administrator));
        $this->post(route('role.switch'), ['role' => 'toe_committee'])->assertForbidden();
        $this->get(route('users.create'))->assertOk()->assertDontSee('Perfil antiguo');
        $this->patch(route('users.status', $legacy), ['is_active' => true])->assertSessionHasErrors('is_active');
        $this->post(route('users.store'), ['name' => 'Prueba', 'dni' => '91234567', 'roles' => [$obsoleteIds['director']], 'password' => 'ClaveTemporal1234', 'password_confirmation' => 'ClaveTemporal1234'])->assertSessionHasErrors('roles.0');
    }

    public function test_transfer_cannot_exclude_course_attendance(): void
    {
        [$tutor,$course,$student,$enrollment] = $this->school();
        $day = SchoolDay::create(['school_year_id' => $enrollment->school_year_id, 'date' => '2026-10-29', 'is_instructional' => true]);
        $session = CourseAttendanceSession::factory()->create(['course_offering_id' => $course->id, 'school_day_id' => $day->id]);
        CourseAttendanceRecord::factory()->create(['course_attendance_session_id' => $session->id, 'enrollment_id' => $enrollment->id]);
        $this->expectException(ValidationException::class);
        app(StudentService::class)->save(['dni' => $student->dni, 'first_name' => $student->first_name, 'last_name' => $student->last_name, 'is_active' => true, 'section_id' => $course->section_id, 'status' => 'withdrawn', 'starts_on' => '2026-03-02', 'ends_on' => '2026-10-28'], $student, $tutor->id);
    }

    public function test_grade_import_is_atomic_scoped_and_publishes_only_requested_rows(): void
    {
        [$tutor,$course,$student] = $this->school();
        $period = AcademicPeriod::create(['school_year_id' => $course->section->school_year_id, 'name' => 'Primero', 'starts_on' => '2026-03-02', 'ends_on' => '2026-05-29', 'is_active' => true]);
        $this->login($course->teacher);
        $csv = "dni,period,achievement,is_published\n".$student->dni.",Primero,B,1\n00000000,Primero,A,1\n";
        $this->post(route('courses.results.import', $course), ['file' => UploadedFile::fake()->createWithContent('resultados.csv', $csv)])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('course_results', 0);
        $csv = "dni,period,achievement,is_published\n".$student->dni.",Primero,B,1\n";
        $this->post(route('courses.results.import', $course), ['file' => UploadedFile::fake()->createWithContent('resultados.csv', $csv)])->assertRedirect();
        $this->assertDatabaseHas('course_results', ['achievement' => 'B', 'is_published' => true]);
        $this->login($tutor);
        $this->get(route('courses.results.template', $course))->assertForbidden();
    }

    public function test_attendance_report_and_parent_summary_respect_period_and_grade(): void
    {
        [$tutor,$course,$student,$enrollment] = $this->school();
        $period = AcademicPeriod::create(['school_year_id' => $enrollment->school_year_id, 'name' => 'Intervalo de prueba', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-29', 'is_active' => true]);
        foreach (['2026-10-29' => 'present', '2026-10-30' => 'unjustified_absence'] as $date => $status) {
            $day = SchoolDay::create(['school_year_id' => $enrollment->school_year_id, 'date' => $date, 'is_instructional' => true]);
            $session = CourseAttendanceSession::factory()->create(['course_offering_id' => $course->id, 'school_day_id' => $day->id]);
            CourseAttendanceRecord::factory()->create(['course_attendance_session_id' => $session->id, 'enrollment_id' => $enrollment->id, 'status' => $status]);
        }
        $guardian = $this->guardian($student, $tutor);
        $this->login($guardian);
        $this->get(route('portal.course', ['student' => $student, 'offering' => $course, 'period_id' => $period->id]))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['percentage'] === 0.0 && $summary['registered_sessions'] === 1);
        $this->login($this->user(RoleName::Coordinator));
        $this->get(route('school-reports.index', ['period_id' => $period->id, 'grade_id' => $course->section->grade_id]))->assertOk()->assertViewHas('rows', fn ($rows) => count($rows) === 1 && $rows[0][3] === 1 && $rows[0][8] === 0.0);
    }
}
