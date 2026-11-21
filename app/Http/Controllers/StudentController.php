<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\PortalFilterRequest;
use App\Models\AcademicPeriod;
use App\Models\AttendanceAlert;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Services\AccessScope;
use App\Services\AdvisorPortalService;
use App\Services\AttendanceSummaryService;
use App\Services\AuditService;
use App\Services\GuardianPortalService;
use Illuminate\Support\Facades\Gate;

class StudentController extends Controller
{
    public function show(PortalFilterRequest $request, Student $student, AuditService $audit, AttendanceSummaryService $service)
    {
        Gate::authorize('view', $student);
        $audit->record('student.view', $student);

        if ($request->user()->activeRole() === RoleName::Tutor) {
            $data = app(AdvisorPortalService::class)->studentData($request->user(), $student);
            foreach ($data['history'] as $record) {
                $audit->record('course.attendance_view', $record);
            }
            foreach ($data['results'] as $result) {
                $audit->record('course.result_view', $result);
            }

            return view('advisor.student', $data);
        }

        $scope = app(AccessScope::class);
        $enrollments = $student->enrollments()->when($request->user()->activeRole() !== RoleName::Guardian, fn ($q) => $q->whereIn('section_id', $scope->sections($request->user())->select('sections.id')));
        $years = SchoolYear::whereIn('id', (clone $enrollments)->select('school_year_id'))->orderByDesc('year')->get();
        $yearId = $request->validated('school_year_id') ?? $years->first()?->id;
        abort_if($yearId && ! $years->contains('id', $yearId), 422, 'El año no corresponde a una matrícula autorizada.');
        $periods = AcademicPeriod::where('school_year_id', $yearId)->where('is_active', true)->get();
        if (! config('school.legacy_attendance_enabled')) {
            $query = app(GuardianPortalService::class)->records($student)->whereHas('enrollment', fn ($q) => $q->where('school_year_id', $yearId));
            if ($request->user()->activeRole() !== RoleName::Guardian) {
                $query->whereHas('session.offering', fn ($q) => $q->whereIn('section_id', $scope->sections($request->user())->select('sections.id')));
            }
            if ($request->validated('period_id')) {
                $period = $periods->firstWhere('id', $request->validated('period_id'));
                abort_unless($period, 422);
                $query->whereHas('session.schoolDay', fn ($q) => $q->whereDate('date', '>=', $period->starts_on)->whereDate('date', '<=', $period->ends_on));
            }
            $query->when($request->validated('from'), fn ($q, $date) => $q->whereHas('session.schoolDay', fn ($d) => $d->whereDate('date', '>=', $date)))->when($request->validated('to'), fn ($q, $date) => $q->whereHas('session.schoolDay', fn ($d) => $d->whereDate('date', '<=', $date)));
            $summary = app(GuardianPortalService::class)->summary(clone $query);
            $records = $query->join('course_attendance_sessions', 'course_attendance_sessions.id', '=', 'course_attendance_records.course_attendance_session_id')->join('school_days', 'school_days.id', '=', 'course_attendance_sessions.school_day_id')->select('course_attendance_records.*')->orderByDesc('school_days.date')->paginate(20)->withQueryString();
            foreach ($records as $record) {
                $audit->record('course.attendance_view', $record);
            }

            return view('students.course-history', compact('student', 'years', 'yearId', 'periods', 'summary', 'records'));
        }
        $base = $service->records($student, $request->user(), $yearId);
        $summary = $service->percentage(clone $base);
        $query = (clone $base)->with('session.schoolDay');
        $periodId = $request->validated('period_id');
        if ($periodId) {
            $period = $periods->firstWhere('id', $periodId);
            abort_unless($period, 422, 'El período no pertenece al año seleccionado.');
            $query->whereHas('session.schoolDay', fn ($q) => $q->whereBetween('date', [$period->starts_on, $period->ends_on]));
        }
        $query->when($request->validated('from'), fn ($q, $date) => $q->whereHas('session.schoolDay', fn ($d) => $d->whereDate('date', '>=', $date)))->when($request->validated('to'), fn ($q, $date) => $q->whereHas('session.schoolDay', fn ($d) => $d->whereDate('date', '<=', $date)));
        $filteredSummary = $service->percentage(clone $query);
        $records = $query->orderByDesc('id')->paginate(20)->withQueryString();
        foreach ($records as $record) {
            $audit->record('attendance.view', $record);
        }
        $today = (clone $base)->with('session.schoolDay')->whereHas('session.schoolDay', fn ($q) => $q->whereDate('date', now()->toDateString()))->first();
        $alerts = AttendanceAlert::where('is_active', true)->whereIn('enrollment_id', (clone $enrollments)->where('school_year_id', $yearId)->select('id'))->get();
        foreach ($alerts as $alert) {
            $audit->record('attendance.alert_view', $alert);
        }

        return view('students.show', compact('student', 'years', 'yearId', 'periods', 'summary', 'filteredSummary', 'records', 'today', 'alerts'));
    }
}
