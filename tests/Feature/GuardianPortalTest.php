<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AbsenceJustification;
use App\Models\AcademicPeriod;
use App\Models\CourseAttendanceAlert;
use App\Models\CourseAttendanceRecord;
use App\Models\CourseAttendanceSession;
use App\Models\CourseOffering;
use App\Models\CourseResult;
use App\Models\Enrollment;
use App\Models\FamilyMeeting;
use App\Models\FamilyWorkshop;
use App\Models\ForumPost;
use App\Models\Role;
use App\Models\SchoolDay;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Models\WorkshopLesson;
use App\Notifications\SchoolNotification;
use App\Services\ConsentService;
use App\Services\CourseAlertService;
use App\Services\GuardianPortalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuardianPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
        Queue::fake();
        Notification::fake();
    }

    public function test_guardian_selects_only_verified_children_and_revoked_selection_is_rejected(): void
    {
        [$guardian,$child,$enrollment,$offering] = $this->family();
        $other = Student::factory()->create();
        $this->login($guardian);
        $this->get('/dashboard')->assertRedirect(route('portal.children'));
        $this->get(route('portal.children'))->assertOk()->assertSee($child->full_name)->assertDontSee($other->full_name);
        $this->post(route('portal.children.select', $other))->assertForbidden();
        $this->post(route('portal.children.select', $child))->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk()->assertSee($child->full_name);
        $guardian->students()->updateExistingPivot($child->id, ['is_active' => false]);
        $this->get(route('portal.home'))->assertRedirect(route('portal.children'));
        $this->get(route('portal.course', [$child, $offering]))->assertForbidden();
    }

    public function test_course_and_report_routes_reject_other_families_even_after_id_tampering(): void
    {
        [$guardian,$child,,$offering] = $this->family();
        [, $otherChild,,$otherCourse] = $this->family();
        $this->login($guardian);
        $this->get(route('portal.course', [$child, $otherCourse]))->assertForbidden();
        $this->get(route('portal.course', [$otherChild, $offering]))->assertForbidden();
        $year = $offering->section->schoolYear;
        $this->get(route('portal.report', [$otherChild, $year]))->assertForbidden();
        $this->get(route('portal.report.pdf', [$otherChild, $year]))->assertForbidden();
        $this->login($offering->teacher);
        $this->get(route('portal.children'))->assertForbidden();
    }

    public function test_guardian_sees_published_literal_results_and_a_valid_informative_pdf(): void
    {
        [$guardian,$child,$enrollment,$offering] = $this->family();
        $published = CourseResult::factory()->create(['course_offering_id' => $offering->id, 'enrollment_id' => $enrollment->id, 'achievement' => 'AD', 'is_published' => true]);
        $otherPeriod = AcademicPeriod::create(['school_year_id' => $enrollment->school_year_id, 'name' => 'Segundo', 'starts_on' => '2026-06-01', 'ends_on' => '2026-08-31']);
        CourseResult::factory()->create(['course_offering_id' => $offering->id, 'enrollment_id' => $enrollment->id, 'academic_period_id' => $otherPeriod->id, 'achievement' => 'C', 'is_published' => false]);
        $this->login($guardian);
        $this->get(route('portal.course', [$child, $offering]))->assertOk()->assertViewHas('results', fn ($results) => $results->count() === 1 && $results->first()->id === $published->id);
        $this->get(route('portal.reports', $child))->assertOk();
        $this->get(route('portal.report', [$child, $offering->section->schoolYear]))->assertOk()->assertViewHas('results', fn ($results) => $results->count() === 1);
        $response = $this->get(route('portal.report.pdf', [$child, $offering->section->schoolYear]));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());
        $this->assertStringEndsWith('%%EOF', $response->getContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal.report_download', 'subject_id' => $child->id]);
    }

    public function test_teacher_records_own_course_with_revision_deadline_and_calendar_validation(): void
    {
        [$guardian,$child,$enrollment,$offering] = $this->family();
        $day = SchoolDay::create(['school_year_id' => $enrollment->school_year_id, 'date' => '2026-10-26', 'is_instructional' => true]);
        $this->login($offering->teacher);
        $data = ['date' => '2026-10-26', 'revision' => 0, 'records' => [['enrollment_id' => $enrollment->id, 'status' => 'present', 'arrival_time' => '08:00']]];
        $this->get(route('courses.record', $offering))->assertOk();
        $this->post(route('courses.attendance', $offering), $data)->assertSessionHasNoErrors()->assertRedirect();
        $session = CourseAttendanceSession::firstOrFail();
        $this->assertDatabaseHas('course_attendance_records', ['enrollment_id' => $enrollment->id, 'status' => 'present']);
        $this->post(route('courses.attendance', $offering), $data)->assertSessionHasErrors('revision');
        $data['revision'] = 1;
        $data['records'][0]['enrollment_id'] = 999999;
        $this->post(route('courses.attendance', $offering), $data)->assertSessionHasErrors('records');
        $data['records'][0]['enrollment_id'] = $enrollment->id;
        $day->update(['is_instructional' => false]);
        $this->post(route('courses.attendance', $offering), $data)->assertSessionHasErrors('date');
        $day->update(['is_instructional' => true]);
        CourseAttendanceSession::whereKey($session->id)->update(['created_at' => now()->subHours(25)]);
        $this->post(route('courses.attendance', $offering), $data)->assertSessionHasErrors('date');
        $this->assertDatabaseHas('audit_logs', ['action' => 'course.attendance_saved']);
    }

    public function test_teacher_cannot_write_another_course_or_unrelated_result(): void
    {
        [,,$enrollment,$offering] = $this->family();
        [,, $otherEnrollment,$otherCourse] = $this->family();
        $this->login($offering->teacher);
        $this->get(route('courses.record', $otherCourse))->assertForbidden();
        $period = AcademicPeriod::create(['school_year_id' => $enrollment->school_year_id, 'name' => 'Período de prueba', 'starts_on' => '2026-03-02', 'ends_on' => '2026-05-29']);
        $data = ['enrollment_id' => $otherEnrollment->id, 'academic_period_id' => $period->id, 'achievement' => 'A', 'is_published' => true];
        $this->post(route('courses.results', $offering), $data)->assertSessionHasErrors('enrollment_id');
        $data['enrollment_id'] = $enrollment->id;
        $data['achievement'] = '20';
        $this->post(route('courses.results', $offering), $data)->assertSessionHasErrors('achievement');
        $data['achievement'] = 'B';
        $this->post(route('courses.results', $offering), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('course_results', ['enrollment_id' => $enrollment->id, 'achievement' => 'B', 'is_published' => true]);
        $this->post(route('courses.content', $offering), ['content' => 'Nuevo contenido publicado.'])->assertRedirect();
        $this->assertSame('Nuevo contenido publicado.', $offering->fresh()->content);
    }

    public function test_percentages_exclude_noninstructional_days_and_keep_courses_separate(): void
    {
        [$guardian,$child,$enrollment,$offering] = $this->family();
        $this->record($offering, $enrollment, '2026-10-19', 'present');
        $absence = $this->record($offering, $enrollment, '2026-10-21', 'unjustified_absence');
        $holiday = $this->record($offering, $enrollment, '2026-10-23', 'unjustified_absence');
        $holiday->session->schoolDay->update(['is_instructional' => false]);
        $other = CourseOffering::factory()->create(['section_id' => $offering->section_id]);
        $this->record($other, $enrollment, '2026-10-26', 'unjustified_absence');
        $portal = app(GuardianPortalService::class);
        $summary = $portal->summary($portal->records($child, $offering));
        $this->assertSame(2, $summary['registered_sessions']);
        $this->assertSame(50.0, $summary['percentage']);
        $absence->update(['status' => 'justified_absence']);
        $this->assertSame(0.0, $portal->summary($portal->records($child, $offering))['percentage']);
        $this->assertSame(100.0, $portal->summary($portal->records($child, $other))['percentage']);
    }

    public function test_justification_is_private_and_tutor_can_approve_after_edit_deadline(): void
    {
        Storage::fake('local');
        [$guardian,$child,$enrollment,$offering] = $this->family();
        $record = $this->record($offering, $enrollment, '2026-10-19', 'unjustified_absence');
        CourseAttendanceSession::whereKey($record->course_attendance_session_id)->update(['created_at' => now()->subDays(7)]);
        $this->login($guardian);
        $this->post(route('portal.justify.store', $record), ['reason' => 'family', 'description' => 'Sustento de la familia para la inasistencia.', 'document' => UploadedFile::fake()->create('sustento.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors()->assertRedirect();
        $request = AbsenceJustification::firstOrFail();
        $this->assertSame('unjustified_absence', $record->fresh()->status->value);
        Storage::disk('local')->assertExists($request->path);
        $this->get(route('justifications.document', $request))->assertOk();
        $other = $this->user(RoleName::Guardian);
        app(ConsentService::class)->accept($other);
        $this->login($other);
        $this->get(route('justifications.document', $request))->assertForbidden();
        $this->login($offering->teacher);
        $this->post(route('justifications.review', $request), ['decision' => 'approved', 'review_note' => 'Documento revisado.'])->assertForbidden();
        $tutor = $this->user(RoleName::Tutor);
        DB::table('tutor_assignments')->insert(['user_id' => $tutor->id, 'section_id' => $offering->section_id, 'is_active' => true]);
        $this->login($tutor);
        $this->get(route('justifications.index'))->assertOk();
        $this->post(route('justifications.review', $request), ['decision' => 'approved', 'review_note' => 'Sustento revisado y aprobado.'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('justified_absence', $record->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'course.attendance_justification_reviewed', 'subject_id' => $record->id, 'user_id' => $tutor->id]);
    }

    public function test_invalid_or_oversized_justification_and_direct_approval_are_rejected(): void
    {
        Storage::fake('local');
        [$guardian,,$enrollment,$offering] = $this->family();
        $record = $this->record($offering, $enrollment, '2026-10-26', 'unjustified_absence');
        $this->login($guardian);
        $this->post(route('portal.justify.store', $record), ['reason' => 'family', 'description' => 'Sustento suficiente de una falta.', 'document' => UploadedFile::fake()->create('sustento.pdf', 5121, 'application/pdf'), 'status' => 'approved'])->assertSessionHasErrors('document');
        $this->assertDatabaseCount('absence_justifications', 0);
        $this->post(route('portal.justify.store', $record), ['reason' => 'family', 'description' => 'Sustento suficiente de una falta.', 'status' => 'approved', 'reviewed_by' => $offering->teacher_id])->assertSessionHasNoErrors()->assertRedirect();
        $request = AbsenceJustification::firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertNull($request->reviewed_by);
    }

    public function test_course_alerts_use_registered_consecutive_sessions_and_are_idempotent(): void
    {
        [,, $enrollment,$offering] = $this->family();
        foreach (['2026-10-19', '2026-10-21', '2026-10-23'] as $date) {
            $this->record($offering, $enrollment, $date, 'unjustified_absence');
        }
        $alerts = app(CourseAlertService::class);
        $this->assertSame(4, $alerts->evaluate());
        $this->assertSame(0, $alerts->evaluate());
        $this->assertSame(4, CourseAttendanceAlert::where('is_active', true)->count());
        CourseAttendanceRecord::where('enrollment_id', $enrollment->id)->update(['status' => 'justified_absence']);
        $this->assertSame(0, $alerts->evaluate());
        $this->assertSame(0, CourseAttendanceAlert::where('is_active', true)->count());
    }

    public function test_workshop_progress_is_per_parent_and_survey_requires_completion(): void
    {
        [$guardian] = $this->family();
        $workshop = FamilyWorkshop::factory()->create();
        $lesson = WorkshopLesson::factory()->create(['family_workshop_id' => $workshop->id]);
        $this->login($guardian);
        $this->get(route('portal.family'))->assertOk();
        $this->get(route('portal.workshop', $workshop))->assertOk();
        $this->post(route('portal.survey', $workshop), ['rating' => 5])->assertSessionHasErrors('rating');
        $this->post(route('portal.lesson.complete', $lesson))->assertRedirect();
        $this->post(route('portal.lesson.complete', $lesson))->assertRedirect();
        $this->assertDatabaseCount('workshop_completions', 1);
        $this->post(route('portal.survey', $workshop), ['rating' => 5, 'comment' => 'Contenido útil.'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('workshop_surveys', ['user_id' => $guardian->id, 'rating' => 5]);
        $other = $this->user(RoleName::Guardian);
        app(ConsentService::class)->accept($other);
        $this->login($other);
        $this->post(route('portal.survey', $workshop), ['rating' => 5])->assertSessionHasErrors('rating');
        $workshop->update(['is_published' => false]);
        $this->get(route('portal.workshop', $workshop))->assertNotFound();
        $this->post(route('portal.lesson.complete', $lesson))->assertNotFound();
    }

    public function test_forum_requires_moderation_and_escapes_content_without_exposing_other_pending_posts(): void
    {
        [$guardian] = $this->family();
        $workshop = FamilyWorkshop::factory()->create();
        $other = $this->user(RoleName::Guardian);
        ForumPost::factory()->create(['family_workshop_id' => $workshop->id, 'user_id' => $other->id, 'body' => 'Participación privada pendiente de otra familia.']);
        $this->login($guardian);
        $body = '<script>alert("prueba")</script> Consulta de convivencia.';
        $this->post(route('portal.forum.post', $workshop), ['body' => $body, 'status' => 'published'])->assertSessionHasNoErrors()->assertRedirect();
        $post = ForumPost::where('user_id', $guardian->id)->firstOrFail();
        $this->assertSame('pending', $post->status);
        $this->get(route('portal.workshop', $workshop))->assertOk()->assertDontSee('Participación privada pendiente de otra familia.')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(', false);
        $this->post(route('family.manage.posts.moderate', $post), ['status' => 'published'])->assertForbidden();
        $coordinator = $this->user(RoleName::Coordinator);
        $this->login($coordinator);
        $this->post(route('family.manage.posts.moderate', $post), ['status' => 'published'])->assertRedirect();
        app(ConsentService::class)->accept($other);
        $this->login($other);
        $this->get(route('portal.workshop', $workshop))->assertOk()->assertSee('Consulta de convivencia.');
    }

    public function test_meeting_attendance_cannot_be_self_assigned_or_recorded_for_another_section(): void
    {
        [$guardian,,$enrollment] = $this->family();
        [,,,$otherCourse] = $this->family();
        $meeting = FamilyMeeting::factory()->create(['section_id' => $enrollment->section_id]);
        $coordinator = $this->user(RoleName::Coordinator);
        $this->login($guardian);
        $this->post(route('family.manage.meetings.attendance', $meeting), ['user_id' => $guardian->id, 'attended' => 1])->assertForbidden();
        $other = FamilyMeeting::factory()->create(['section_id' => $otherCourse->section_id]);
        $this->login($coordinator);
        $this->post(route('family.manage.meetings.attendance', $other), ['user_id' => $guardian->id, 'attended' => 1])->assertSessionHasErrors('user_id');
        $this->post(route('family.manage.meetings.attendance', $meeting), ['user_id' => $guardian->id, 'attended' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('meeting_attendances', ['user_id' => $guardian->id, 'family_meeting_id' => $meeting->id, 'attended' => true]);
        $this->login($guardian);
        $this->get(route('portal.family'))->assertOk()->assertViewHas('attendance', fn ($rows) => $rows->count() === 1);
    }

    public function test_administrator_manages_courses_and_family_modules_while_guardian_is_denied(): void
    {
        [$guardian,,$enrollment,$offering] = $this->family();
        $admin = $this->user(RoleName::Administrator);
        $this->login($admin);
        foreach ([route('courses.manage'), route('family.manage.index'), route('family.manage.workshops.create')] as $url) {
            $this->get($url)->assertOk();
        }
        $this->post(route('courses.store'), ['name' => 'Nuevo curso', 'icon' => 'book', 'section_id' => $enrollment->section_id, 'teacher_id' => $offering->teacher_id, 'content' => 'Contenido de prueba.', 'is_active' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('family.manage.workshops.store'), ['title' => 'Taller nuevo', 'description' => 'Acompañamiento a las familias.', 'is_published' => 1, 'is_active' => 1, 'lessons' => [['title' => 'Primer contenido', 'content' => 'Material de aprendizaje para las familias.']]])->assertSessionHasNoErrors()->assertRedirect();
        $workshop = FamilyWorkshop::where('title', 'Taller nuevo')->firstOrFail();
        $this->get(route('family.manage.workshops.edit', $workshop))->assertOk();
        $this->post(route('courses.hours'), ['user_id' => $offering->teacher_id, 'office_hours' => 'Martes 10:00 a 11:00.'])->assertRedirect();
        $this->assertSame('Martes 10:00 a 11:00.', $offering->teacher->fresh()->office_hours);
        $this->login($guardian);
        $this->get(route('courses.manage'))->assertForbidden();
        $this->get(route('family.manage.index'))->assertForbidden();
    }

    public function test_workshop_content_with_participation_is_preserved_and_meetings_can_be_deactivated(): void
    {
        [$guardian,,$enrollment] = $this->family();
        $workshop = FamilyWorkshop::factory()->create();
        $lesson = WorkshopLesson::factory()->create(['family_workshop_id' => $workshop->id]);
        $this->login($guardian);
        $this->post(route('portal.lesson.complete', $lesson))->assertRedirect();
        $this->post(route('portal.survey', $workshop), ['rating' => 4])->assertRedirect();
        $coordinator = $this->user(RoleName::Coordinator);
        $this->login($coordinator);
        $this->get(route('family.manage.index'))->assertOk()->assertViewHas('workshops', fn ($rows) => $rows->first()->surveys_count === 1);
        $this->put(route('family.manage.workshops.update', $workshop), ['title' => $workshop->title, 'description' => $workshop->description, 'is_active' => 1, 'is_published' => 1, 'lessons' => [['title' => $lesson->title, 'content' => 'Contenido reemplazado sin conservar historial.']]])->assertSessionHasErrors('lessons');
        $this->assertSame($lesson->content, $lesson->fresh()->content);
        $meeting = FamilyMeeting::factory()->create(['section_id' => $enrollment->section_id]);
        $this->get(route('family.manage.meetings.show', $meeting))->assertOk();
        $data = ['title' => $meeting->title, 'section_id' => $meeting->section_id, 'scheduled_at' => $meeting->scheduled_at->format('Y-m-d\TH:i'), 'location' => $meeting->location, 'is_published' => 1, 'is_active' => 0];
        $this->put(route('family.manage.meetings.update', $meeting), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse($meeting->fresh()->is_active);
        $this->assertDatabaseHas('family_meetings', ['id' => $meeting->id]);
    }

    public function test_pending_justification_blocks_teacher_state_changes_and_queued_delivery_respects_revoked_permissions(): void
    {
        [$guardian,$child,$enrollment,$offering] = $this->family();
        $record = $this->record($offering, $enrollment, '2026-10-26', 'unjustified_absence');
        $this->login($guardian);
        $this->post(route('portal.justify.store', $record), ['reason' => 'family', 'description' => 'Sustento de una inasistencia para revisar.'])->assertRedirect();
        $this->login($offering->teacher);
        $this->post(route('courses.attendance', $offering), ['date' => '2026-10-26', 'revision' => 1, 'records' => [['enrollment_id' => $enrollment->id, 'status' => 'present', 'arrival_time' => '08:00']]])->assertSessionHasErrors('records');
        $this->assertSame('unjustified_absence', $record->fresh()->status->value);
        $notification = new SchoolNotification('Asistencia', 'Registro', '/portal', ['kind' => 'course_attendance', 'student_id' => $child->id, 'course_record_id' => $record->id, 'status' => 'unjustified_absence']);
        $this->assertTrue($notification->shouldSend($guardian, 'database'));
        Role::where('name', 'guardian')->first()->permissions()->detach(DB::table('permissions')->where('name', 'students.view')->value('id'));
        $this->assertFalse($notification->shouldSend($guardian, 'database'));
    }

    public function test_assistant_records_daily_only_in_assigned_grades_and_cannot_publish_results(): void
    {
        [,, $enrollment,$offering] = $this->family();
        [,,, $otherCourse] = $this->family();
        $assistant = $this->user(RoleName::Assistant);
        DB::table('assistant_assignments')->insert(['user_id' => $assistant->id, 'grade_id' => $offering->section->grade_id, 'school_year_id' => $enrollment->school_year_id, 'is_active' => true]);
        $this->login($assistant);
        $this->get(route('courses.record', $offering))->assertOk()->assertDontSee('Registrar nivel de logro');
        $this->get(route('courses.mine'))->assertOk();
        $this->post(route('courses.results', $offering), [])->assertForbidden();
        $this->post(route('courses.content', $offering), ['content' => 'Cambio no autorizado.'])->assertForbidden();
        $this->get(route('attendance.edit', $offering->section))->assertOk();
        $this->post(route('attendance.store', $offering->section), [])->assertSessionHasErrors('records');
        $this->post(route('courses.attendance', $offering), [])->assertForbidden();
        $otherYear = SchoolYear::create(['year' => 2027, 'starts_on' => '2027-03-01', 'ends_on' => '2027-12-17']);
        $otherSection = Section::create(['school_year_id' => $otherYear->id, 'grade_id' => $offering->section->grade_id, 'name' => 'A']);
        $foreign = CourseOffering::factory()->create(['section_id' => $otherSection->id]);
        $this->get(route('courses.record', $foreign))->assertForbidden();
        DB::table('assistant_assignments')->where('user_id', $assistant->id)->update(['is_active' => false]);
        $this->get(route('courses.record', $offering))->assertForbidden();
    }

    private function family(): array
    {
        $offering = CourseOffering::factory()->create();
        $student = Student::factory()->create();
        $enrollment = Enrollment::create(['student_id' => $student->id, 'school_year_id' => $offering->section->school_year_id, 'section_id' => $offering->section_id, 'starts_on' => '2026-03-02']);
        $guardian = $this->user(RoleName::Guardian);
        $guardian->students()->attach($student, ['relationship' => 'Apoderado', 'is_active' => true, 'verified_by' => $guardian->id, 'verified_at' => now()]);
        app(ConsentService::class)->accept($guardian);

        return [$guardian, $student, $enrollment, $offering];
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

    private function record(CourseOffering $offering, Enrollment $enrollment, string $date, string $status): CourseAttendanceRecord
    {
        $day = SchoolDay::where('school_year_id', $enrollment->school_year_id)->whereDate('date', $date)->first() ?? SchoolDay::create(['school_year_id' => $enrollment->school_year_id, 'date' => $date, 'is_instructional' => true]);
        $session = CourseAttendanceSession::factory()->create(['course_offering_id' => $offering->id, 'school_day_id' => $day->id]);

        return CourseAttendanceRecord::factory()->create(['course_attendance_session_id' => $session->id, 'enrollment_id' => $enrollment->id, 'status' => $status, 'arrival_time' => $status === 'present' ? '08:00:00' : null]);
    }
}
