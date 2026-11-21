<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AcademicPeriod;
use App\Models\CourseOffering;
use App\Models\CourseResult;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\Student;
use App\Models\TutoringSession;
use App\Models\User;
use App\Services\SpreadsheetRows;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentTutoringWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(10, 0));
    }

    public function test_coordinator_provisions_student_and_classroom_only_accepts_answers_during_the_scheduled_window(): void
    {
        [$offering, $tutor, $session] = $this->classroom();
        $enrollment = $this->enroll($offering);
        $student = $enrollment->student;
        $coordinator = $this->account(RoleName::Coordinator);
        $this->enter($coordinator);
        $this->get(route('students.edit', $student))->assertOk()->assertSee('Acceso a la sala de cómputo');
        $this->post(route('students.portal-access', $student), [
            'account_action' => 'save', 'password' => 'TemporalEscolar2026', 'password_confirmation' => 'TemporalEscolar2026',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $account = User::where('student_profile_id', $student->id)->sole();
        $this->assertSame($student->dni, $account->dni);
        $this->assertSame(RoleName::Student, $account->defaultRole());
        $this->assertTrue(Hash::check('TemporalEscolar2026', $account->password));
        $this->assertTrue($account->must_change_password);

        $this->enter($tutor);
        $this->get(route('student-tutoring.staff.index'))->assertOk()->assertSee($session->topic);
        $activity = $this->publish($session, 'checkin:practice');
        $this->publish($session, 'study:practice');

        auth()->logout();
        $this->flushSession();
        $this->post(route('login.store', RoleName::Student->value), ['dni' => $student->dni, 'password' => 'TemporalEscolar2026'])
            ->assertRedirect(route('password.change'));
        $this->post(route('password.change.store'), [
            'current_password' => 'TemporalEscolar2026', 'password' => 'ClavePersonal2026', 'password_confirmation' => 'ClavePersonal2026',
        ])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('student-tutoring.home'));
        $this->get(route('student-tutoring.home'))->assertOk()->assertSee('¿Cómo estoy hoy?');
        $this->get(route('student-tutoring.activity', $activity))->assertOk();
        $this->post(route('student-tutoring.submit', $activity), ['student_answers' => [5, 4, 3, 2]])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('student-tutoring.submit', $activity), ['student_answers' => [1, 1, 1, 1]])->assertRedirect();
        $this->get(route('student-tutoring.activity', $activity))->assertOk()->assertSee('5 de 5')->assertDontSee('1 de 5');
        $this->assertDatabaseCount('student_tutoring_responses', 1);
        $this->assertDatabaseCount('student_tutoring_accesses', 1);
        $this->get(route('students.show', $student))->assertForbidden();

        $other = $this->studentAccount($this->enroll(CourseOffering::factory()->create()));
        $this->enter($other);
        $this->get(route('student-tutoring.activity', $activity))->assertNotFound();

        $this->travelTo(now()->setTime(11, 0));
        $this->enter($account->fresh());
        $this->post(route('student-tutoring.submit', $activity), ['student_answers' => [5, 4, 3, 2]])->assertForbidden();

        $this->enter($coordinator);
        $this->post(route('academics.years.status', $offering->section->schoolYear), ['action' => 'close', 'reason' => 'Cierre para comprobar la visibilidad.'])->assertRedirect();
        $this->enter($account->fresh());
        $this->get(route('student-tutoring.home'))->assertOk()->assertDontSee('¿Cómo estoy hoy?');
        $this->get(route('student-tutoring.activity', $activity))->assertNotFound();
        $this->enter($tutor);
        $this->get(route('student-tutoring.staff.activity', $activity))->assertNotFound();
    }

    public function test_private_journal_requests_and_goals_are_scoped_and_can_be_followed_up(): void
    {
        [$offering, $tutor] = $this->classroom();
        $enrollment = $this->enroll($offering);
        $student = $this->studentAccount($enrollment);
        $peer = $this->studentAccount($this->enroll($offering));
        $period = $this->period($offering);
        $this->enter($student);

        $this->post(route('student-tutoring.entries.store', 'journal'), ['private_content' => 'Reflexión que solo leerá mi tutor'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('student-tutoring.entries.store', 'request'), ['private_content' => 'Necesito ayuda con un conflicto', 'category' => 'conflict'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('student-tutoring.entries.store', 'goal'), ['private_content' => 'Repasar cada jueves', 'academic_period_id' => $period->id])->assertSessionHasNoErrors()->assertRedirect();
        $entries = DB::table('student_tutoring_entries')->get()->keyBy('kind');
        $this->assertStringNotContainsString('Reflexión', $entries['journal']->content);
        $this->post(route('student-tutoring.progress', $entries['goal']->id), ['progress' => 60, 'private_note' => 'Practiqué con una guía'])->assertSessionHasNoErrors()->assertRedirect();
        $this->get(route('student-tutoring.goal', $entries['goal']->id))->assertOk()->assertSee('Practiqué con una guía')->assertSee('60 %');

        $this->enter($peer);
        $this->get(route('student-tutoring.entries', 'journal'))->assertOk()->assertDontSee('Reflexión que solo leerá mi tutor');
        $this->get(route('student-tutoring.goal', $entries['goal']->id))->assertNotFound();

        $this->enter($this->account(RoleName::Tutor));
        $this->get(route('student-tutoring.staff.entry', $entries['journal']->id))->assertNotFound();
        $coordinator = $this->account(RoleName::Coordinator);
        $this->enter($coordinator);
        $this->get(route('student-tutoring.staff.entry', $entries['journal']->id))->assertNotFound();
        $this->get(route('student-tutoring.staff.entry', $entries['request']->id))->assertNotFound();

        $this->enter($tutor);
        $this->get(route('student-tutoring.staff.entry', $entries['journal']->id))->assertOk()->assertSee('Reflexión que solo leerá mi tutor');
        $this->post(route('student-tutoring.staff.feedback', $entries['journal']->id), ['action' => 'reply', 'private_feedback' => 'Revisemos tu meta el jueves'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('student-tutoring.staff.feedback', $entries['request']->id), ['action' => 'refer'])->assertSessionHasNoErrors()->assertRedirect();
        $this->enter($this->account(RoleName::Administrator));
        $this->get(route('student-tutoring.staff.entry', $entries['request']->id))->assertForbidden();
        $this->enter($coordinator);
        $this->get(route('student-tutoring.staff.index'))->assertOk()->assertSee('Buzón derivado')->assertDontSee('Publicar actividad');
        $this->get(route('student-tutoring.staff.entry', $entries['journal']->id))->assertNotFound();
        $this->get(route('student-tutoring.staff.entry', $entries['request']->id))->assertOk()->assertSee('Necesito ayuda con un conflicto');
        $this->post(route('student-tutoring.staff.feedback', $entries['request']->id), ['action' => 'reply', 'private_feedback' => 'Podemos conversar mañana'])->assertSessionHasNoErrors()->assertRedirect();
        $this->enter($student);
        $this->get(route('student-tutoring.entries', 'journal'))->assertOk()->assertSee('Revisemos tu meta el jueves');
        $this->get(route('student-tutoring.entries', 'request'))->assertOk()->assertSee('Atendida')->assertSee('Podemos conversar mañana');
    }

    public function test_teacher_can_publish_a_case_and_only_approved_reflections_appear_to_peers(): void
    {
        [$offering, , $session] = $this->classroom();
        $student = $this->studentAccount($this->enroll($offering));
        $peer = $this->studentAccount($this->enroll($offering));

        $this->enter($offering->teacher);
        $activity = $this->publish($session, 'forum:practice', ['prompt' => '¿Cómo podrías ayudar a un compañero que quedó fuera del grupo?']);
        $this->get(route('student-tutoring.staff.activity', ['activity' => $activity, 'project' => 1]))
            ->assertOk()->assertSee('Caso para conversar')->assertDontSee($student->name);

        $this->enter($student);
        $this->post(route('student-tutoring.submit', $activity), ['forum_text' => 'Invitaría al compañero a participar.'])->assertSessionHasNoErrors()->assertRedirect();
        $response = DB::table('student_tutoring_responses')->where('activity_id', $activity)->value('id');
        $this->enter($peer);
        $this->get(route('student-tutoring.activity', $activity))->assertOk()->assertDontSee('Invitaría al compañero a participar.');

        $this->enter($this->account(RoleName::Teacher));
        $this->patch(route('student-tutoring.staff.moderate', [$activity, $response]), ['moderation' => 'approved'])->assertNotFound();
        $this->enter($offering->teacher);
        $this->patch(route('student-tutoring.staff.moderate', [$activity, $response]), ['moderation' => 'approved'])->assertRedirect();

        $this->enter($peer);
        $this->get(route('student-tutoring.activity', $activity))->assertOk()->assertSee('Invitaría al compañero a participar.')->assertDontSee($student->name);
    }

    public function test_research_counts_authorized_pairs_separately_from_usage_and_exports_without_private_data(): void
    {
        [$offering, $tutor, $session] = $this->classroom();
        $period = $this->period($offering);
        $coordinator = $this->account(RoleName::Coordinator);
        $this->enter($tutor);
        $pre = $this->publish($session, 'personal:pre');
        $practice = $this->publish($session, 'checkin:practice');
        $nextSession = TutoringSession::factory()->create([
            'section_id' => $offering->section_id, 'user_id' => $tutor->id, 'scheduled_on' => today()->addDay(),
        ]);
        $post = $this->publish($nextSession, 'personal:post');
        $sus = $this->publish($nextSession, 'sus:evaluation');
        $enrollments = collect(range(1, 5))->map(fn () => $this->enroll($offering));
        $first = $enrollments->first();
        $this->enter($this->studentAccount($first));
        $this->get(route('student-tutoring.activity', $pre))->assertNotFound();
        $this->post(route('student-tutoring.submit', $practice), ['student_answers' => [4, 4, 4, 4]])->assertRedirect();
        $this->post(route('student-tutoring.entries.store', 'journal'), ['private_content' => 'Texto privado que nunca se exporta'])->assertRedirect();
        CourseResult::factory()->create(['course_offering_id' => $offering->id, 'enrollment_id' => $first->id, 'academic_period_id' => $period->id, 'is_published' => true]);

        foreach ($enrollments as $enrollment) {
            $this->enter($coordinator);
            $this->post(route('students.research-permission', $enrollment->student_id), ['research_authorized' => '1'])->assertSessionHasNoErrors()->assertRedirect();
            $this->enter(User::where('student_profile_id', $enrollment->student_id)->first() ?? $this->studentAccount($enrollment));
            $this->post(route('student-tutoring.submit', $pre), ['student_answers' => array_fill(0, 6, 2)])->assertSessionHasErrors('assent');
            $this->post(route('student-tutoring.submit', $pre), ['student_answers' => array_fill(0, 6, 2), 'assent' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->travelTo(now()->addDay());
        foreach ($enrollments as $enrollment) {
            $this->enter(User::where('student_profile_id', $enrollment->student_id)->sole());
            $this->post(route('student-tutoring.submit', $post), ['student_answers' => array_fill(0, 6, 4), 'assent' => '1'])->assertSessionHasNoErrors()->assertRedirect();
            $this->post(route('student-tutoring.submit', $sus), ['student_answers' => [5, 1, 5, 1, 5, 1, 5, 1, 5, 1, 4], 'assent' => '1'])->assertSessionHasNoErrors()->assertRedirect();
        }
        $filters = ['school_year_id' => $offering->section->school_year_id, 'section_id' => $offering->section_id, 'period_id' => $period->id];
        $this->enter($coordinator);
        $this->get(route('school-reports.index', $filters + ['type' => 'research']))
            ->assertOk()->assertDontSee('Texto privado que nunca se exporta')->assertDontSee($first->student->dni)
            ->assertViewHas('rows', function (array $rows): bool {
                $indicators = collect($rows)->keyBy(1);

                return $indicators['Usabilidad SUS (0–100)'][2] === 100.0
                    && $indicators['Satisfacción (1–5)'][2] === 4.0
                    && $indicators['Actividades guiadas completadas'][3] === 5
                    && $indicators['Cumplimiento de actividades guiadas (%)'][2] === 20.0
                    && $indicators['Accesos en horario de tutoría'][2] === 10
                    && $indicators['Autoconocimiento y autoestima · cambio'][2] === 2.0
                    && $indicators['Logros A'][2] === 1;
            });
        $this->get(route('school-reports.index', $filters + ['type' => 'research-pairs']))
            ->assertOk()->assertDontSee($first->student->full_name)->assertViewHas('rows', fn (array $rows): bool => count($rows) === 15);
        $xlsx = $this->get(route('school-reports.export', $filters + ['format' => 'xlsx', 'type' => 'research-pairs']))->assertOk()->assertDownload('reporte-research-pairs.xlsx');
        $download = UploadedFile::fake()->createWithContent('pares.xlsx', $xlsx->streamedContent());
        $exportedRows = iterator_to_array(app(SpreadsheetRows::class)->read($download->getPathname(), 'xlsx'));
        $this->assertCount(16, $exportedRows);
        $this->assertSame(['Código de estudio', 'Instrumento', 'Versión', 'Indicador', 'Pre (1–5)', 'Post (1–5)', 'Cambio'], $exportedRows[0]);
        $this->assertStringNotContainsString($first->student->dni, json_encode($exportedRows, JSON_THROW_ON_ERROR));
        $this->post(route('students.research-permission', $first->student_id), ['research_authorized' => '0'])->assertRedirect();
        $this->get(route('school-reports.index', $filters + ['type' => 'research-pairs']))->assertOk()->assertViewHas('rows', []);

        $this->enter($this->account(RoleName::Administrator));
        $this->get(route('school-reports.index', $filters + ['type' => 'research']))->assertForbidden();
    }

    /** @return array{CourseOffering, User, TutoringSession} */
    private function classroom(): array
    {
        $offering = CourseOffering::factory()->create();
        $offering->section->schoolYear->update(['is_active' => true]);
        $tutor = $this->account(RoleName::Tutor);
        $tutor->tutoredSections()->attach($offering->section_id, ['is_active' => true]);
        $session = TutoringSession::factory()->create(['section_id' => $offering->section_id, 'user_id' => $tutor->id, 'scheduled_on' => today()]);

        return [$offering, $tutor, $session];
    }

    private function enroll(CourseOffering $offering): Enrollment
    {
        return Enrollment::create([
            'student_id' => Student::factory()->create()->id,
            'section_id' => $offering->section_id, 'school_year_id' => $offering->section->school_year_id,
            'starts_on' => '2026-03-02', 'status' => 'active',
        ]);
    }

    private function period(CourseOffering $offering): AcademicPeriod
    {
        return AcademicPeriod::create(['school_year_id' => $offering->section->school_year_id, 'name' => 'Cuarto bimestre', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-18', 'is_active' => true]);
    }

    private function publish(TutoringSession $session, string $selection, array $details = []): int
    {
        $this->post(route('student-tutoring.staff.publish'), [
            'session_id' => $session->id, 'instrument_phase' => $selection, 'opens' => '09:30', 'closes' => '10:30',
        ] + $details)->assertSessionHasNoErrors()->assertRedirect(route('student-tutoring.staff.index'));

        return DB::table('student_tutoring_activities')->latest('id')->value('id');
    }

    private function studentAccount(Enrollment $enrollment): User
    {
        return $this->account(RoleName::Student, ['student_profile_id' => $enrollment->student_id, 'dni' => $enrollment->student->dni]);
    }

    private function account(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->sole());

        return $user->refresh();
    }

    private function enter(User $user): void
    {
        $this->actingAs($user)->withSession(['active_role' => $user->defaultRole()->value, 'session_version' => $user->session_version, 'last_activity_at' => now()->timestamp]);
    }
}
