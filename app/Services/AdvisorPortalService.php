<?php

namespace App\Services;

use App\Models\AbsenceJustification;
use App\Models\Communication;
use App\Models\CommunicationRecipient;
use App\Models\CourseAttendanceAlert;
use App\Models\CourseAttendanceRecord;
use App\Models\CourseOffering;
use App\Models\CourseResult;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class AdvisorPortalService
{
    public function sections(User $user): Builder
    {
        return app(AccessScope::class)->sections($user)->where('is_active', true)->with('grade.educationLevel', 'schoolYear');
    }

    public function courses(User $user, ?int $sectionId = null): Builder
    {
        return CourseOffering::with('course', 'teacher', 'section.grade', 'section.schoolYear')->where('is_active', true)->whereHas('course', fn ($q) => $q->where('is_active', true))->whereIn('section_id', $this->sections($user)->select('sections.id'))->when($sectionId, fn ($q) => $q->where('section_id', $sectionId));
    }

    public function students(User $user, ?int $sectionId = null, ?string $search = null): Builder
    {
        return app(AccessScope::class)->students($user)->where('is_active', true)->whereHas('enrollments', fn ($q) => $q->where('status', 'active')->whereDate('starts_on', '<=', now()->toDateString())->where(fn ($e) => $e->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()->toDateString()))->whereIn('section_id', $this->sections($user)->select('sections.id'))->when($sectionId, fn ($e) => $e->where('section_id', $sectionId)))->when($search, fn ($q) => $q->where(fn ($s) => $s->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')->orWhere('dni', 'like', '%'.$search.'%')));
    }

    public function records(User $user, ?Student $student = null, ?int $sectionId = null): Builder
    {
        return CourseAttendanceRecord::with('enrollment.student', 'session.offering.course', 'session.schoolDay')->whereHas('session.offering', fn ($q) => $q->whereIn('section_id', $this->sections($user)->select('sections.id'))->when($sectionId, fn ($s) => $s->where('section_id', $sectionId)))->whereHas('session.schoolDay', fn ($q) => $q->where('is_instructional', true)->whereDate('date', '<=', now()->toDateString()))->when($student, fn ($q) => $q->whereHas('enrollment', fn ($e) => $e->where('student_id', $student->id)))->when(! $user->permits('attendance.view'), fn ($q) => $q->whereRaw('1=0'));
    }

    public function justifications(User $user): Builder
    {
        return AbsenceJustification::with('record.enrollment.student', 'record.session.offering.course', 'record.session.schoolDay')->whereHas('record.session.offering', fn ($q) => $q->whereIn('section_id', $this->sections($user)->select('sections.id')))->when(! $user->permits('attendance.record'), fn ($q) => $q->whereRaw('1=0'));
    }

    public function alerts(User $user, ?int $sectionId = null): Builder
    {
        return CourseAttendanceAlert::with('enrollment.student', 'offering.course', 'offering.section.grade')->where('is_active', true)->whereHas('offering', fn ($q) => $q->whereIn('section_id', $this->sections($user)->select('sections.id'))->when($sectionId, fn ($s) => $s->where('section_id', $sectionId)))->when(! $user->permits('attendance.view'), fn ($q) => $q->whereRaw('1=0'));
    }

    public function dashboard(User $user): array
    {
        Gate::forUser($user)->authorize('advisor-portal');
        $sections = $this->sections($user)->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')->whereHas('student', fn ($s) => $s->where('is_active', true))])->orderByDesc('school_year_id')->orderBy('name')->get();
        $courses = $this->courses($user)->get();
        $today = $this->records($user)->whereHas('session.schoolDay', fn ($q) => $q->whereDate('date', now()->toDateString()));
        $statusCounts = (clone $today)->selectRaw('status,COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $pending = $this->justifications($user)->where('status', 'pending');
        $alerts = $this->alerts($user)->orderByDesc('threshold')->limit(60)->get()->unique(fn ($a) => $a->enrollment_id.'|'.$a->course_offering_id)->take(5)->values();
        $communications = Communication::where('user_id', $user->id)->where('type', 'appointment')->where('scheduled_at', '>=', now())->whereHas('recipients', fn ($q) => $q->whereIn('student_id', $this->students($user)->select('students.id')))->when(! $user->permits('communications.send'), fn ($q) => $q->whereRaw('1=0'));
        $appointments = (clone $communications)->withCount('recipients')->orderBy('scheduled_at')->limit(4)->get();
        $reschedules = CommunicationRecipient::where('response', 'reschedule_requested')->whereIn('student_id', $this->students($user)->select('students.id'))->whereIn('communication_id', (clone $communications)->select('id'))->count();
        $stats = ['sections' => $sections->count(), 'students' => $this->students($user)->count(), 'courses' => $courses->count(), 'pending_justifications' => (clone $pending)->count(), 'present_today' => (int) $statusCounts->get('present', 0) + (int) $statusCounts->get('late', 0), 'absent_today' => (int) $statusCounts->get('unjustified_absence', 0) + (int) $statusCounts->get('justified_absence', 0), 'registered_today' => (int) $statusCounts->sum(), 'reschedule_requests' => $reschedules];

        return compact('sections', 'courses', 'stats', 'alerts', 'appointments') + ['pendingRequests' => $pending->latest()->limit(4)->get()];
    }

    public function studentData(User $user, Student $student): array
    {
        Gate::forUser($user)->authorize('advisor-portal');
        Gate::forUser($user)->authorize('view', $student);
        $allowed = $this->sections($user)->select('sections.id');
        $enrollment = $student->enrollments()->with('section.grade', 'section.schoolYear')->whereIn('section_id', $allowed)->latest('starts_on')->firstOrFail();
        $courses = $this->courses($user)->whereIn('section_id', $student->enrollments()->select('section_id'))->get();
        $records = $this->records($user, $student);
        $summary = app(GuardianPortalService::class)->summary(clone $records);
        $history = $records->join('course_attendance_sessions', 'course_attendance_sessions.id', '=', 'course_attendance_records.course_attendance_session_id')->join('school_days', 'school_days.id', '=', 'course_attendance_sessions.school_day_id')->select('course_attendance_records.*')->orderByDesc('school_days.date')->paginate(15);
        $results = CourseResult::with('offering.course', 'period')->where('is_published', true)->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))->whereHas('offering', fn ($q) => $q->whereIn('section_id', $this->sections($user)->select('sections.id')))->get();

        return compact('student', 'enrollment', 'courses', 'summary', 'history', 'results');
    }
}
