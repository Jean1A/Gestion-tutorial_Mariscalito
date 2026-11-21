<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Jobs\ImportStudents;
use App\Jobs\NotifyAttendance;
use App\Models\AttendanceAlert;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\CommunicationRecipient;
use App\Models\EducationLevel;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Role;
use App\Models\SchoolDay;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use App\Notifications\SchoolNotification;
use App\Services\AttendanceAlertService;
use App\Services\AttendanceSummaryService;
use App\Services\ConsentService;
use App\Services\SpreadsheetRows;
use App\Services\StudentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class MvpOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $admin;

    private Section $section;

    private Student $student;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['school.legacy_attendance_enabled' => true]);
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(10, 0));
        Queue::fake();
        $this->admin = $this->user(RoleName::Administrator);
        $this->tutor = $this->user(RoleName::Tutor);
        $year = SchoolYear::create(['year' => 2026, 'starts_on' => '2026-03-02', 'ends_on' => '2026-12-18']);
        $level = EducationLevel::create(['name' => 'Secundaria']);
        $grade = Grade::create(['name' => 'Primero', 'education_level_id' => $level->id]);
        $this->section = Section::create(['name' => 'A', 'school_year_id' => $year->id, 'grade_id' => $grade->id]);
        $this->student = Student::factory()->create();
        $this->enrollment = Enrollment::create(['student_id' => $this->student->id, 'school_year_id' => $year->id, 'section_id' => $this->section->id, 'starts_on' => '2026-03-02']);
        DB::table('tutor_assignments')->insert(['user_id' => $this->tutor->id, 'section_id' => $this->section->id, 'is_active' => true]);
        foreach (['2026-10-07' => true, '2026-10-08' => false, '2026-10-09' => true] as $date => $lective) {
            SchoolDay::create(['school_year_id' => $year->id, 'date' => $date, 'is_instructional' => $lective]);
        }
    }

    public function test_all_administrator_modules_render_and_staff_cannot_manage_them(): void
    {
        $this->loginAs($this->admin);
        foreach (['/dashboard', '/academics/years', '/academics/levels', '/academics/grades', '/academics/sections', '/academics/periods', '/academics/years/create', '/calendar', '/students', '/students/create', '/students/'.$this->student->id.'/edit', '/assignments', '/imports', '/imports/template', '/settings', '/notifications'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->loginAs($this->tutor);
        foreach (['/settings', '/students', '/imports', '/calendar', '/assignments'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/sections/'.$this->section->id)->assertOk()->assertSee($this->student->full_name);
        $this->get('/attendance/'.$this->section->id)->assertOk();
        $this->get('/communications/create')->assertOk();
    }

    public function test_attendance_edit_audit_revision_and_configurable_deadline(): void
    {
        $this->loginAs($this->tutor);
        $this->post('/attendance/'.$this->section->id, $this->attendance('unjustified_absence'))->assertRedirect();
        Queue::assertPushed(NotifyAttendance::class);
        $record = AttendanceRecord::firstOrFail();
        $this->post('/attendance/'.$this->section->id, $this->attendance('justified_absence', 1, 'Certificado presentado'))->assertRedirect();
        $record->refresh();
        $this->assertSame('justified_absence', $record->status->value);
        $audit = DB::table('audit_logs')->where('action', 'attendance.updated')->first();
        $changes = json_decode($audit->changes, true);
        $this->assertSame('unjustified_absence', $changes['before']['status']);
        $this->assertSame('justified_absence', $changes['after']['status']);
        $this->assertSame($this->tutor->id, (int) $audit->user_id);
        $this->post('/attendance/'.$this->section->id, $this->attendance('present', 1))->assertSessionHasErrors('revision');
        Setting::whereKey('attendance.edit_hours')->update(['value' => json_encode(1)]);
        AttendanceSession::query()->update(['created_at' => now()->subHours(2)]);
        $this->post('/attendance/'.$this->section->id, $this->attendance('present', 2))->assertSessionHasErrors('date');
        $this->assertSame('justified_absence', $record->fresh()->status->value);
    }

    public function test_foreign_roster_holidays_and_missing_arrival_are_rejected_atomically(): void
    {
        $this->loginAs($this->tutor);
        $data = $this->attendance('present');
        $data['records'][0]['enrollment_id'] = 999999;
        $this->post('/attendance/'.$this->section->id, $data)->assertSessionHasErrors('records');
        $data = $this->attendance('present');
        $data['records'][0]['arrival_time'] = null;
        $this->post('/attendance/'.$this->section->id, $data)->assertSessionHasErrors('records');
        $data = $this->attendance('present');
        $data['date'] = '2026-10-08';
        $this->post('/attendance/'.$this->section->id, $data)->assertSessionHasErrors('date');
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('attendance_sessions', 0);
        $other = Section::create(['school_year_id' => $this->section->school_year_id, 'grade_id' => $this->section->grade_id, 'name' => 'B']);
        $this->post('/attendance/'.$other->id, $this->attendance('present'))->assertForbidden();
    }

    public function test_assistant_cannot_approve_justifications(): void
    {
        $assistant = $this->user(RoleName::Assistant);
        DB::table('assistant_assignments')->insert(['user_id' => $assistant->id, 'grade_id' => $this->section->grade_id, 'school_year_id' => $this->section->school_year_id, 'is_active' => true]);
        $this->loginAs($assistant);
        $this->post('/attendance/'.$this->section->id, $this->attendance('justified_absence', 0, 'Sustento'))->assertSessionHasErrors('records');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_percentage_uses_only_registered_instructional_days_and_justification_removes_absence(): void
    {
        $this->record('2026-10-07', 'unjustified_absence');
        $this->record('2026-10-08', 'unjustified_absence');
        $record = $this->record('2026-10-09', 'present');
        $summary = app(AttendanceSummaryService::class);
        $this->assertSame(['registered_days' => 2, 'unjustified_absences' => 1, 'percentage' => 50.0], $summary->enrollment($this->enrollment));
        AttendanceRecord::where('id', '!=', $record->id)->update(['status' => 'justified_absence']);
        $this->assertSame(0.0, $summary->enrollment($this->enrollment)['percentage']);
        SchoolDay::create(['school_year_id' => $this->section->school_year_id, 'date' => '2026-10-06', 'is_instructional' => true]);
        $this->assertSame(2, $summary->enrollment($this->enrollment)['registered_days']);
    }

    public function test_alerts_are_idempotent_configurable_and_skip_holidays(): void
    {
        Notification::fake();
        $this->record('2026-10-07', 'unjustified_absence');
        $this->record('2026-10-09', 'unjustified_absence');
        Setting::whereKey('attendance.consecutive_days')->update(['value' => json_encode(2)]);
        $alerts = app(AttendanceAlertService::class);
        $this->assertSame(4, $alerts->evaluate());
        $this->assertSame(0, $alerts->evaluate());
        Notification::assertSentToTimes($this->tutor, SchoolNotification::class, 4);
        $this->assertSame(2, app(AttendanceSummaryService::class)->consecutive($this->enrollment));
        Setting::whereKey('attendance.percentage_thresholds')->update(['value' => json_encode([80])]);
        $this->assertSame(1, $alerts->evaluate());
        $this->assertSame(2, AttendanceAlert::where('is_active', true)->count());
        SchoolDay::whereDate('date', '2026-10-08')->update(['is_instructional' => true]);
        $this->assertSame(1, app(AttendanceSummaryService::class)->consecutive($this->enrollment));
    }

    public function test_consent_is_explicit_revocable_and_rechecked_when_a_child_is_added(): void
    {
        $guardian = $this->guardian();
        $this->loginAs($guardian);
        $this->get('/dashboard')->assertRedirect('/privacy/consent');
        $this->get('/privacy')->assertOk()->assertSee('MARISCAL CASTILLA');
        $this->post('/privacy/consent', [])->assertSessionHasErrors('consent');
        $this->post('/privacy/consent', ['consent' => 1])->assertRedirect('/dashboard');
        $this->get('/students/'.$this->student->id)->assertOk();
        $new = Student::factory()->create();
        $guardian->students()->attach($new, ['relationship' => 'Madre', 'is_active' => true, 'verified_at' => now(), 'verified_by' => $this->admin->id]);
        $this->get('/dashboard')->assertRedirect('/privacy/consent');
        $this->post('/privacy/consent', ['consent' => 1])->assertRedirect();
        $this->post('/privacy/withdraw')->assertRedirect('/privacy/consent');
        $this->get('/dashboard')->assertRedirect('/privacy/consent');
        $this->assertDatabaseHas('audit_logs', ['action' => 'privacy.consent_withdrawn']);
    }

    public function test_queued_notifications_revalidate_link_consent_and_attendance(): void
    {
        Notification::fake();
        $guardian = $this->guardian();
        app(ConsentService::class)->accept($guardian);
        $record = $this->record('2026-10-09', 'unjustified_absence');
        (new NotifyAttendance($record->id, 'unjustified_absence'))->handle();
        Notification::assertSentTo($guardian, SchoolNotification::class);
        $notification = new SchoolNotification('Asistencia', 'Prueba', '/students/'.$this->student->id, ['kind' => 'attendance', 'student_id' => $this->student->id, 'record_id' => $record->id, 'status' => 'unjustified_absence']);
        $this->assertTrue($notification->shouldSend($guardian, 'database'));
        $record->update(['status' => 'present']);
        $this->assertFalse($notification->shouldSend($guardian, 'database'));
        $record->update(['status' => 'unjustified_absence']);
        app(ConsentService::class)->withdraw($guardian);
        $this->assertFalse($notification->shouldSend($guardian, 'database'));
        app(ConsentService::class)->accept($guardian);
        $guardian->students()->updateExistingPivot($this->student->id, ['is_active' => false]);
        $this->assertFalse($notification->shouldSend($guardian, 'mail'));
    }

    public function test_communications_require_own_recipient_and_record_reading_and_reschedule(): void
    {
        $guardian = $this->guardian();
        app(ConsentService::class)->accept($guardian);
        $this->loginAs($this->tutor);
        $this->post('/communications', ['school_year_id' => $this->section->school_year_id, 'type' => 'appointment', 'target' => 'student', 'student_dni' => $this->student->dni, 'title' => 'Entrevista con tutor', 'body' => 'Coordinaremos el acompañamiento.', 'scheduled_at' => '2026-10-12T10:00', 'location' => 'Tutoría'])->assertRedirect();
        $recipient = CommunicationRecipient::firstOrFail();
        $other = $this->guardian();
        app(ConsentService::class)->accept($other);
        $this->loginAs($other);
        $this->get('/communications/'.$recipient->id)->assertForbidden();
        $this->post('/communications/'.$recipient->id.'/read')->assertForbidden();
        $this->loginAs($guardian);
        $this->get('/communications/'.$recipient->id)->assertOk();
        $this->assertNull($recipient->fresh()->read_at);
        $this->post('/communications/'.$recipient->id.'/read')->assertRedirect();
        $this->post('/communications/'.$recipient->id.'/respond', ['response' => 'reschedule_requested', 'response_note' => 'Solicito el martes por la tarde.'])->assertRedirect();
        $this->assertSame('reschedule_requested', $recipient->fresh()->response);
        $this->assertSame('2026-10-12 10:00', $recipient->communication->scheduled_at->format('Y-m-d H:i'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'communication.responded']);
    }

    public function test_csv_import_is_private_handles_bad_rows_and_retries_without_duplicates(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('imports/test.csv', "dni,first_name,last_name,birth_date\n91234567,Ana,Prueba,2013-01-01\ninvalid,Error,Fila,2013-01-01\n");
        $import = StudentImport::create(['user_id' => $this->admin->id, 'section_id' => $this->section->id, 'path' => 'imports/test.csv', 'format' => 'csv', 'status' => 'queued']);
        $job = new ImportStudents($import->id);
        $job->handle(app(SpreadsheetRows::class), app(StudentService::class));
        $job->handle(app(SpreadsheetRows::class), app(StudentService::class));
        $this->assertSame(1, $import->fresh()->imported_count);
        $this->assertSame(1, $import->fresh()->error_count);
        $this->assertDatabaseCount('student_import_rows', 2);
        $this->assertSame(1, Student::where('dni', '91234567')->count());
        $this->loginAs($this->tutor);
        $this->get('/imports/'.$import->id)->assertForbidden();
    }

    public function test_xlsx_import_preserves_dni_leading_zeros_and_dates(): void
    {
        Storage::fake('local');
        Storage::disk('local')->makeDirectory('imports');
        $path = Storage::disk('local')->path('imports/students.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $cells = fn ($values) => new Row(array_map(fn ($value) => Cell::fromValue($value), $values));
        $writer->addRow($cells(['dni', 'first_name', 'last_name', 'birth_date']));
        $writer->addRow($cells(['01234567', 'Ana', 'Prueba Excel', '2013-01-01']));
        $writer->close();
        $import = StudentImport::create(['user_id' => $this->admin->id, 'section_id' => $this->section->id, 'path' => 'imports/students.xlsx', 'format' => 'xlsx', 'status' => 'queued']);
        (new ImportStudents($import->id))->handle(app(SpreadsheetRows::class), app(StudentService::class));
        $this->assertSame(1, $import->fresh()->imported_count);
        $student = Student::where('dni', '01234567')->firstOrFail();
        $this->assertSame('2013-01-01', $student->birth_date->toDateString());
    }

    public function test_notification_list_hides_revoked_children_and_other_users(): void
    {
        $guardian = $this->guardian();
        app(ConsentService::class)->accept($guardian);
        $id = Str::uuid()->toString();
        $guardian->notifications()->create(['id' => $id, 'type' => SchoolNotification::class, 'data' => ['title' => 'Solo para mi hijo', 'message' => 'Asistencia', 'url' => '/students/'.$this->student->id, 'student_id' => $this->student->id]]);
        $this->loginAs($guardian);
        $this->get('/notifications')->assertOk()->assertSee('Solo para mi hijo');
        $other = $this->guardian();
        app(ConsentService::class)->accept($other);
        $this->loginAs($other);
        $this->post('/notifications/'.$id.'/read')->assertNotFound();
        $guardian->students()->updateExistingPivot($this->student->id, ['is_active' => false]);
        $this->loginAs($guardian);
        $this->get('/notifications')->assertOk()->assertDontSee('Solo para mi hijo');
        $this->post('/notifications/'.$id.'/read')->assertNotFound();
    }

    public function test_student_transfer_preserves_attendance_and_prevents_retroactive_loss(): void
    {
        $record = $this->record('2026-10-07', 'present');
        $section = Section::create(['school_year_id' => $this->section->school_year_id, 'grade_id' => $this->section->grade_id, 'name' => 'B']);
        $this->loginAs($this->admin);
        $data = ['dni' => $this->student->dni, 'first_name' => $this->student->first_name, 'last_name' => $this->student->last_name, 'birth_date' => $this->student->birth_date?->toDateString(), 'is_active' => true, 'section_id' => $section->id, 'status' => 'active', 'starts_on' => '2026-10-06'];
        $this->put('/students/'.$this->student->id, $data)->assertSessionHasErrors('ends_on');
        $this->assertDatabaseCount('enrollments', 1);
        $data['starts_on'] = '2026-10-09';
        $this->put('/students/'.$this->student->id, $data)->assertRedirect();
        $this->assertDatabaseCount('enrollments', 2);
        $this->assertSame('2026-10-08', $this->enrollment->fresh()->ends_on->toDateString());
        $this->assertSame($this->enrollment->id, $record->fresh()->enrollment_id);
    }

    public function test_period_overlap_calendar_changes_and_permission_ceiling(): void
    {
        $this->loginAs($this->admin);
        $data = ['school_year_id' => $this->section->school_year_id, 'name' => 'Primero', 'starts_on' => '2026-03-02', 'ends_on' => '2026-05-29', 'is_active' => 1];
        $this->post('/academics/periods', $data)->assertRedirect();
        $data['name'] = 'Segundo';
        $data['starts_on'] = '2026-05-20';
        $this->post('/academics/periods', $data)->assertSessionHasErrors('starts_on');
        $this->post('/calendar', ['school_year_id' => $this->section->school_year_id, 'date' => '2026-10-08', 'is_instructional' => 1, 'description' => 'Recuperación'])->assertRedirect();
        $this->assertTrue(SchoolDay::whereDate('date', '2026-10-08')->where('is_instructional', true)->exists());
        $guardianRole = Role::where('name', 'guardian')->firstOrFail();
        $manage = DB::table('permissions')->where('name', 'users.manage')->value('id');
        $this->post('/settings/permissions/'.$guardianRole->id, ['permissions' => [$manage]])->assertSessionHasErrors('permissions');
        $this->assertFalse($guardianRole->permissions()->where('name', 'users.manage')->exists());
    }

    private function attendance(string $status, int $revision = 0, ?string $reason = null): array
    {
        return ['date' => '2026-10-09', 'revision' => $revision, 'records' => [['enrollment_id' => $this->enrollment->id, 'status' => $status, 'arrival_time' => in_array($status, ['present', 'late']) ? '08:00' : null, 'justification' => $reason]]];
    }

    private function record(string $date, string $status): AttendanceRecord
    {
        $day = SchoolDay::whereDate('date', $date)->firstOrFail();
        $session = AttendanceSession::create(['section_id' => $this->section->id, 'school_day_id' => $day->id, 'recorded_by' => $this->tutor->id]);

        return AttendanceRecord::create(['attendance_session_id' => $session->id, 'enrollment_id' => $this->enrollment->id, 'status' => $status, 'arrival_time' => $status === 'present' ? '08:00:00' : null]);
    }

    private function user(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->firstOrFail());

        return $user;
    }

    private function guardian(): User
    {
        $guardian = $this->user(RoleName::Guardian);
        $guardian->students()->attach($this->student, ['relationship' => 'Madre', 'is_active' => true, 'verified_by' => $this->admin->id, 'verified_at' => now()]);

        return $guardian;
    }

    private function loginAs(User $user): void
    {
        $user->refresh();
        $this->actingAs($user)->withSession(['active_role' => $user->defaultRole()->value, 'session_version' => $user->session_version, 'last_activity_at' => now()->timestamp]);
    }
}
