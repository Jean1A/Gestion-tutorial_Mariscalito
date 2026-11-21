<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AcademicPeriod;
use App\Models\CourseOffering;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SchoolDay;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\StaffStudentMessage;
use App\Models\Student;
use App\Models\SupportRecord;
use App\Models\TutoringSession;
use App\Models\User;
use App\Notifications\SchoolNotification;
use App\Services\ConsentService;
use App\Services\GuardianPortalService;
use App\Services\SpreadsheetRows;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FiveRoleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
        Notification::fake();
    }

    public function test_coordinator_can_operate_school_and_delegate_accounts_without_escalation(): void
    {
        $coordinator = $this->user(RoleName::Coordinator);
        $admin = $this->user(RoleName::Administrator);
        $this->login($coordinator);

        $this->get(route('dashboard'))->assertOk()->assertViewIs('coordination.home');
        foreach ([
            route('academics.index', 'years'),
            route('assignments.index'),
            route('students.index'),
            route('courses.manage'),
            route('family.manage.index'),
            route('coordination.movements'),
            route('school-reports.index'),
            route('users.create'),
        ] as $page) {
            $this->get($page)->assertOk();
        }
        $this->get(route('settings.index'))->assertForbidden();
        $this->get(route('users.edit', $admin))->assertForbidden();
        $this->patch(route('users.status', $admin), ['is_active' => false])->assertForbidden();
        $data = ['name' => 'Tutor nuevo', 'dni' => '91234567', 'password' => 'TemporalPrueba1234', 'password_confirmation' => 'TemporalPrueba1234'];
        $this->post(route('users.store'), $data + ['roles' => [$admin->roles->sole()->id]])->assertSessionHasErrors('roles.0');
        $this->post(route('users.store'), $data + ['roles' => [Role::where('name', 'tutor')->value('id')]])->assertRedirect(route('users.index'));
        $this->assertTrue(User::where('dni', '91234567')->firstOrFail()->must_change_password);
    }

    public function test_prepared_year_and_batch_promotion_keep_history_and_allow_individual_permanence(): void
    {
        [$offering, $first] = $this->classroom();
        $second = $this->enroll($offering);
        $skipped = $this->enroll($offering);
        $origin = $offering->section;
        $this->login($this->user(RoleName::Coordinator));
        $yearData = ['year' => 2027, 'starts_on' => '2027-03-01', 'ends_on' => '2027-12-17'];
        $this->post(route('academics.store', 'years'), $yearData + ['is_active' => true])->assertSessionHasErrors('is_active');
        $this->post(route('academics.store', 'years'), $yearData + ['is_active' => false])->assertRedirect();
        $year = SchoolYear::where('year', 2027)->firstOrFail();
        $this->post(route('academics.store', 'sections'), ['school_year_id' => $year->id, 'grade_id' => $origin->grade_id, 'name' => 'Permanencia', 'is_active' => true])->assertRedirect();
        $permanence = Section::where('school_year_id', $year->id)->firstOrFail();
        $nextGrade = $origin->grade->replicate();
        $nextGrade->name = 'Segundo';
        $nextGrade->save();
        $destination = Section::create(['school_year_id' => $year->id, 'grade_id' => $nextGrade->id, 'name' => 'A']);
        $this->get(route('coordination.movements', ['source_id' => $origin->id]))->assertOk()->assertSee($first->student->full_name);
        $batch = [
            'source_id' => $origin->id, 'destination_id' => $destination->id, 'starts_on' => '2027-03-01',
            'reason' => 'Promoción aprobada según acta con una permanencia.',
            'enrollments' => [$first->id, $second->id], 'overrides' => [$second->id => $permanence->id],
        ];
        $this->post(route('coordination.movements.store'), $batch)->assertRedirect()->assertSessionHas('status', '2 matrículas registradas. 0 movimientos ya aplicados.');
        $this->post(route('coordination.movements.store'), $batch)->assertRedirect()->assertSessionHas('status', '0 matrículas registradas. 2 movimientos ya aplicados.');
        $this->assertDatabaseCount('enrollments', 5);
        $this->assertDatabaseHas('enrollments', ['student_id' => $first->student_id, 'section_id' => $destination->id]);
        $this->assertDatabaseHas('enrollments', ['student_id' => $second->student_id, 'section_id' => $permanence->id]);
        $this->assertDatabaseMissing('enrollments', ['student_id' => $skipped->student_id, 'school_year_id' => $year->id]);
        $this->assertSame($first->id, app(GuardianPortalService::class)->enrollment($first->student)->id);
        $this->assertTrue($origin->schoolYear->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'enrollment.batch_moved', 'subject_id' => $origin->id]);
    }

    public function test_daily_and_course_attendance_have_independent_marks_and_permissions(): void
    {
        [$offering, $enrollment, $tutor, $assistant] = $this->classroom();
        SchoolDay::create(['school_year_id' => $enrollment->school_year_id, 'date' => '2026-11-05', 'is_instructional' => true]);
        $data = ['date' => '2026-11-05', 'revision' => 0, 'records' => [['enrollment_id' => $enrollment->id, 'status' => 'present', 'arrival_time' => '08:00']]];
        $this->login($assistant);
        $this->get(route('attendance.edit', $offering->section))->assertOk()->assertViewHas('canRecord', true);
        $this->post(route('attendance.store', $offering->section), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('courses.attendance', $offering), $data)->assertForbidden();
        $this->login($tutor);
        $this->post(route('attendance.store', $offering->section), $data)->assertForbidden();
        $this->post(route('courses.attendance', $offering), $data)->assertForbidden();
        $offering->course->update(['is_tutoring' => true]);
        $data['records'][0]['status'] = 'unjustified_absence';
        $this->post(route('courses.attendance', $offering), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_records', ['enrollment_id' => $enrollment->id, 'status' => 'present']);
        $this->assertDatabaseHas('course_attendance_records', ['enrollment_id' => $enrollment->id, 'status' => 'unjustified_absence']);
        $this->get(route('courses.record', $offering))->assertOk()->assertSee('Jornada diaria');
        $this->login($this->guardian($enrollment->student));
        $this->get(route('portal.daily', $enrollment->student))->assertOk()->assertSee('Presente');
        $this->get(route('portal.daily', Student::factory()->create()))->assertForbidden();
    }

    public function test_teacher_incident_reaches_tutor_and_only_family_summary_is_published(): void
    {
        [$offering, $enrollment, $tutor] = $this->classroom();
        [$foreign, $other] = $this->classroom();
        $data = [
            'student_id' => $enrollment->student_id, 'section_id' => $offering->section_id,
            'course_offering_id' => $offering->id, 'occurred_on' => '2026-11-05',
            'kind' => 'incident', 'title' => 'Informe interno', 'description' => 'Testimonio que permanece en el registro interno.',
            'visibility' => 'shared', 'status' => 'closed', 'severity' => 'medium',
            'family_summary' => 'El docente no puede publicar directamente.', 'revision' => 0,
        ];
        $this->login($offering->teacher);
        $this->get(route('support.create', ['course_offering_id' => $offering->id]))->assertOk()->assertSee('Curso donde ocurrió');
        $this->post(route('support.store'), array_replace($data, ['section_id' => $foreign->section_id, 'student_id' => $other->student_id]))->assertForbidden();
        $this->post(route('support.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $record = SupportRecord::sole();
        $this->assertSame('internal', $record->visibility);
        $this->assertSame('open', $record->status);
        Notification::assertSentTo($tutor, SchoolNotification::class, fn ($notice) => $notice->metadata['kind'] === 'support_report');
        $this->put(route('support.update', $record), $data)->assertForbidden();
        $this->login($tutor);
        $this->get(route('support.show', $record))->assertOk()->assertSee($data['description']);
        $close = array_replace($data, ['revision' => 1, 'dimension' => 'social', 'closure_note' => 'Resultado interno de la intervención.', 'family_summary' => 'Reunión de seguimiento con la familia.']);
        $this->put(route('support.update', $record), $close)->assertSessionHasErrors('closure_note');
        $this->post(route('support.follow-up', $record), ['note' => 'Entrevista interna con los involucrados.'])->assertRedirect();
        $this->put(route('support.update', $record), array_replace($close, ['revision' => 2]))->assertRedirect()->assertSessionHasNoErrors();
        $this->login($this->guardian($enrollment->student));
        $this->get(route('support.show', $record))->assertOk()->assertSee($close['family_summary'])
            ->assertDontSee($data['title'])->assertDontSee($data['description'])->assertDontSee($close['closure_note'])->assertDontSee('Entrevista interna con los involucrados.');
        $this->login($this->user(RoleName::Coordinator));
        $this->get(route('support.show', $record))->assertOk()->assertSee('Dimensión Social')->assertDontSee($data['description']);
        $this->get(route('support.edit', $record))->assertForbidden();
    }

    public function test_teacher_contacts_messages_and_export_enforce_assignment(): void
    {
        [$offering, $enrollment, $tutor] = $this->classroom();
        [$foreign, $other] = $this->classroom();
        $guardian = $this->guardian($enrollment->student);
        $otherGuardian = $this->guardian($other->student);
        $thread = route('courses.staff-messages', [$offering, $enrollment]);
        $this->login($offering->teacher);
        $this->get(route('courses.contacts', $offering))->assertOk()->assertSee($guardian->name)->assertDontSee($otherGuardian->name);
        $this->get(route('courses.contacts', $foreign))->assertForbidden();
        $this->get(route('courses.staff-messages', [$offering, $other]))->assertForbidden();
        $this->post($thread, ['body' => 'Revisemos el plan académico de este estudiante.'])->assertRedirect();
        $this->get(route('courses.results.export', $offering))->assertOk()->assertDownload('logros-curso-'.$offering->id.'.xlsx');
        $this->get(route('courses.results.export', $foreign))->assertForbidden();
        $this->login($tutor);
        $this->get($thread)->assertOk()->assertSee('Revisemos el plan académico');
        $this->assertNotNull(StaffStudentMessage::sole()->read_at);
        $tutor->tutoredSections()->updateExistingPivot($offering->section_id, ['is_active' => false]);
        $this->get($thread)->assertForbidden();
    }

    public function test_session_material_versions_are_private_and_role_scoped(): void
    {
        Storage::fake('local');
        [$offering, $enrollment, $tutor] = $this->classroom();
        $this->login($tutor);
        $pdf = fn () => UploadedFile::fake()->createWithContent('guia.pdf', "%PDF-1.4\n%%EOF\n");
        $data = [
            'section_id' => $offering->section_id, 'scheduled_on' => '2026-11-05', 'topic' => 'Hábitos de estudio',
            'objectives' => 'Organización del tiempo.', 'status' => 'planned',
            'dimensions' => ['personal', 'learning'], 'revision' => 0,
        ];
        $this->post(route('support.sessions.store'), $data + ['materials' => ['guide' => $pdf()], 'share_guide' => true])->assertRedirect()->assertSessionHasNoErrors();
        $session = TutoringSession::sole();
        $this->put(route('support.sessions.update', $session), array_replace($data, ['revision' => 1, 'materials' => ['exam' => $pdf()]]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['personal', 'learning'], $session->fresh()->dimensions);
        $this->assertCount(2, $session->fresh()->materials);
        $this->get(route('support.sessions.material', [$session, 1]))->assertOk()->assertDownload('guia-1.pdf');
        $this->get(route('support.sessions.material', [$session, 2]))->assertOk()->assertDownload('evaluacion-2.pdf');
        $this->login($this->guardian($enrollment->student));
        $this->get(route('support.sessions.material', [$session, 2]))->assertForbidden();
        $this->get(route('portal.materials', $enrollment->student))->assertOk()->assertSee('Descargar guía');
        $this->get(route('portal.materials.download', [$enrollment->student, $session, 1]))->assertOk()->assertDownload('guia-1.pdf');
        $this->get(route('portal.materials.download', [$enrollment->student, $session, 2]))->assertForbidden();
    }

    public function test_closed_year_blocks_changes_and_reopening_leaves_it_in_preparation(): void
    {
        [$offering, $enrollment] = $this->classroom();
        $year = $offering->section->schoolYear;
        $this->login($this->user(RoleName::Coordinator));
        $route = route('academics.years.status', $year);
        $this->post($route, ['action' => 'close', 'reason' => 'Cierre con acta institucional.'])->assertRedirect();
        $this->assertNotNull($year->fresh()->closed_at);
        $this->login($offering->teacher);
        $this->post(route('courses.results', $offering), ['enrollment_id' => $enrollment->id])->assertNotFound();
        $this->get(route('courses.record', $offering))->assertNotFound();
        $this->login($this->user(RoleName::Coordinator));
        $this->post($route, ['action' => 'reopen', 'reason' => 'Rectificación autorizada por acta.'])->assertRedirect();
        $this->assertNull($year->fresh()->closed_at);
        $this->assertFalse($year->fresh()->is_active);
    }

    public function test_published_grade_correction_requires_reason_and_xlsx_contains_the_revised_result(): void
    {
        [$offering, $enrollment] = $this->classroom();
        $period = AcademicPeriod::create([
            'school_year_id' => $enrollment->school_year_id,
            'name' => 'Cuarto bimestre',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-12-18',
            'is_active' => true,
        ]);
        $this->login($offering->teacher);
        $route = route('courses.results', $offering);
        $payload = ['enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id, 'achievement' => 'B', 'is_published' => true];
        $this->post($route, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($route, array_replace($payload, ['achievement' => 'A']))->assertSessionHasErrors('reason');
        $this->post($route, array_replace($payload, ['achievement' => 'A', 'reason' => 'Acta de rectificación de la evaluación.']))->assertRedirect()->assertSessionHasNoErrors();
        $response = $this->get(route('courses.results.export', [$offering, 'period_id' => $period->id]))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'test-course-results-');
        try {
            file_put_contents($path, $response->streamedContent());
            $rows = iterator_to_array(app(SpreadsheetRows::class)->read($path, 'xlsx'), false);
            $this->assertSame(['DNI', 'Estudiante', 'Curso', 'Período', 'Logro', 'Estado'], $rows[0]);
            $this->assertSame([$enrollment->student->dni, $enrollment->student->full_name, $offering->course->name, 'Cuarto bimestre', 'A', 'Publicado'], $rows[1]);
        } finally {
            unlink($path);
        }
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
        $this->actingAs($user)->withSession([
            'active_role' => $user->defaultRole()->value,
            'session_version' => $user->session_version,
            'last_activity_at' => now()->timestamp,
        ]);
    }

    private function classroom(): array
    {
        $offering = CourseOffering::factory()->create();
        $tutor = $this->user(RoleName::Tutor);
        $tutor->tutoredSections()->attach($offering->section_id, ['is_active' => true]);
        $assistant = $this->user(RoleName::Assistant);
        DB::table('assistant_assignments')->insert(['user_id' => $assistant->id, 'grade_id' => $offering->section->grade_id, 'school_year_id' => $offering->section->school_year_id, 'is_active' => true]);

        return [$offering, $this->enroll($offering), $tutor, $assistant];
    }

    private function enroll(CourseOffering $offering): Enrollment
    {
        return Enrollment::create([
            'student_id' => Student::factory()->create()->id,
            'school_year_id' => $offering->section->school_year_id,
            'section_id' => $offering->section_id,
            'starts_on' => '2026-03-02',
        ]);
    }

    private function guardian(Student $student): User
    {
        $guardian = $this->user(RoleName::Guardian);
        $guardian->students()->attach($student, ['relationship' => 'Apoderado', 'is_active' => true, 'verified_by' => $guardian->id, 'verified_at' => now()]);
        app(ConsentService::class)->accept($guardian);

        return $guardian;
    }
}
