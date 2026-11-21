<?php

namespace App\Http\Controllers;

use App\Http\Requests\PortalCourseRequest;
use App\Models\AcademicPeriod;
use App\Models\CourseAttendanceAlert;
use App\Models\CourseOffering;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\TutoringSession;
use App\Models\User;
use App\Services\AttendanceSummaryService;
use App\Services\AuditService;
use App\Services\GuardianPortalService;
use App\Services\InformativeReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuardianPortalController extends Controller
{
    public function children(Request $request, GuardianPortalService $portal, AuditService $audit): View
    {
        $children = $portal->children($request->user());
        foreach ($children as $child) {
            $audit->record('portal.child_list_view', $child);
        }

        return view('guardian.children', compact('children'));
    }

    public function select(Request $request, Student $student, GuardianPortalService $portal, AuditService $audit): RedirectResponse
    {
        $portal->child($request->user(), $student);
        $request->session()->put('guardian.student_id', $student->id);
        $audit->record('portal.child_selected', $student);

        return redirect()->route('portal.home');
    }

    public function home(Request $request, GuardianPortalService $portal, AuditService $audit): View|RedirectResponse
    {
        $child = $portal->selected($request->user());
        if (! $child) {
            return redirect()->route('portal.children');
        }
        $enrollment = $portal->enrollment($child);
        $courses = $portal->offerings($child)->where('section_id', $enrollment?->section_id)->where('is_active', true)->get();
        $records = $portal->records($child)->whereHas('enrollment', fn ($q) => $q->where('school_year_id', $enrollment?->school_year_id));
        $summary = $portal->summary($records);
        $tutors = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'tutor'))->whereIn('id', DB::table('tutor_assignments')->where('section_id', $enrollment?->section_id)->where('is_active', true)->select('user_id'))->get();
        $today = (clone $records)->whereHas('session.schoolDay', fn ($q) => $q->whereDate('date', now()->toDateString()))->get();
        $alerts = CourseAttendanceAlert::with('offering.course')->where('is_active', true)->whereIn('enrollment_id', $child->enrollments()->select('id'))->get();
        $alerts = $alerts->groupBy(fn ($alert) => $alert->course_offering_id.'|'.$alert->type)->map(fn ($group) => $group->sortByDesc('threshold')->first())->values();
        $audit->record('portal.home_view', $child);

        return view('guardian.home', compact('child', 'enrollment', 'courses', 'summary', 'tutors', 'today', 'alerts'));
    }

    public function daily(Request $request, Student $student, GuardianPortalService $portal, AttendanceSummaryService $attendance, AuditService $audit): View
    {
        $child = $portal->child($request->user(), $student);
        $enrollment = $portal->enrollment($child);
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $query = $attendance->records($child, $request->user())->with('session.schoolDay')
            ->whereHas('session.schoolDay', fn ($days) => $days->when($data['from'] ?? null, fn ($q, $from) => $q->whereDate('date', '>=', $from))
                ->when($data['to'] ?? null, fn ($q, $to) => $q->whereDate('date', '<=', $to)));
        $summary = $attendance->percentage(clone $query);
        $records = $query->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
            ->join('school_days', 'school_days.id', '=', 'attendance_sessions.school_day_id')
            ->select('attendance_records.*')->orderByDesc('school_days.date')->paginate(20)->withQueryString();
        $audit->record('portal.daily_attendance_view', $child, $data);

        return view('guardian.daily', compact('child', 'enrollment', 'summary', 'records'));
    }

    public function materials(Request $request, Student $student, GuardianPortalService $portal): View
    {
        $child = $portal->child($request->user(), $student);
        $enrollment = $portal->enrollment($child);
        $sessions = $portal->tutoringMaterials($child)->latest('scheduled_on')->paginate(15);

        return view('guardian.materials', compact('child', 'enrollment', 'sessions'));
    }

    public function material(Request $request, Student $student, TutoringSession $session, int $version, GuardianPortalService $portal, AuditService $audit): StreamedResponse
    {
        $child = $portal->child($request->user(), $student);
        abort_unless($portal->tutoringMaterials($child)->whereKey($session->id)->exists(), 403);
        $material = $session->materials[$version - 1] ?? null;
        abort_unless($material && $material['kind'] === 'guide' && ($material['shared'] ?? false), 403);
        abort_unless(Storage::disk('local')->exists($material['path']), 404);
        $audit->record('portal.guide_download', $session, ['student_id' => $child->id, 'version' => $version]);

        return Storage::disk('local')->download($material['path'], 'guia-'.$version.'.pdf');
    }

    public function courses(Request $request, Student $student, GuardianPortalService $portal, AuditService $audit): View
    {
        $child = $portal->child($request->user(), $student);
        $enrollment = $portal->enrollment($child);
        $courses = $portal->offerings($child)->whereHas('section', fn ($q) => $q->where('school_year_id', $enrollment?->school_year_id))->get();
        $audit->record('portal.courses_view', $student);

        return view('guardian.courses', compact('child', 'enrollment', 'courses'));
    }

    public function course(PortalCourseRequest $request, Student $student, CourseOffering $offering, GuardianPortalService $portal, AuditService $audit): View
    {
        $child = $portal->child($request->user(), $student);
        $portal->assertCourse($child, $offering);
        $enrollment = $student->enrollments()->with('section.grade', 'section.schoolYear')->where('section_id', $offering->section_id)->latest('starts_on')->first();
        $periods = AcademicPeriod::where('school_year_id', $offering->section->school_year_id)->where('is_active', true)->get();
        $historyQuery = $portal->records($child, $offering);
        if ($request->validated('period_id')) {
            $period = $periods->firstWhere('id', $request->validated('period_id'));
            abort_unless($period, 422);
            $historyQuery->whereHas('session.schoolDay', fn ($q) => $q->whereBetween('date', [$period->starts_on, $period->ends_on]));
        }
        $historyQuery->when($request->validated('from'), fn ($q, $d) => $q->whereHas('session.schoolDay', fn ($s) => $s->whereDate('date', '>=', $d)))->when($request->validated('to'), fn ($q, $d) => $q->whereHas('session.schoolDay', fn ($s) => $s->whereDate('date', '<=', $d)));
        $summary = $portal->summary(clone $historyQuery);
        $history = $historyQuery->join('course_attendance_sessions', 'course_attendance_sessions.id', '=', 'course_attendance_records.course_attendance_session_id')->join('school_days', 'school_days.id', '=', 'course_attendance_sessions.school_day_id')->select('course_attendance_records.*')->orderByDesc('school_days.date')->paginate(15)->withQueryString();
        foreach ($history as $record) {
            $audit->record('course.attendance_view', $record);
        }
        $results = $offering->results()->with('period')->where('is_published', true)->whereIn('enrollment_id', $child->enrollments()->select('id'))->get()->sortBy(fn ($r) => $r->period->starts_on);
        foreach ($results as $result) {
            $audit->record('course.result_view', $result);
        }
        $audit->record('portal.course_view', $offering, ['student_id' => $child->id]);

        return view('guardian.course', compact('child', 'enrollment', 'offering', 'summary', 'results', 'history', 'periods'));
    }

    public function absences(PortalCourseRequest $request, Student $student, CourseOffering $offering, GuardianPortalService $portal, AuditService $audit): View
    {
        $child = $portal->child($request->user(), $student);
        $portal->assertCourse($child, $offering);
        $enrollment = $portal->enrollment($child);
        $query = $portal->records($child, $offering)->whereIn('status', ['unjustified_absence', 'justified_absence']);
        $query->when($request->validated('from'), fn ($q, $date) => $q->whereHas('session.schoolDay', fn ($d) => $d->whereDate('date', '>=', $date)))->when($request->validated('to'), fn ($q, $date) => $q->whereHas('session.schoolDay', fn ($d) => $d->whereDate('date', '<=', $date)));
        $records = $query->join('course_attendance_sessions', 'course_attendance_sessions.id', '=', 'course_attendance_records.course_attendance_session_id')->join('school_days', 'school_days.id', '=', 'course_attendance_sessions.school_day_id')->select('course_attendance_records.*')->orderByDesc('school_days.date')->paginate(20)->withQueryString();
        foreach ($records as $record) {
            $audit->record('course.absence_view', $record);
        }

        return view('guardian.absences', compact('child', 'enrollment', 'offering', 'records'));
    }

    public function reports(Request $request, Student $student, GuardianPortalService $portal, AuditService $audit): View
    {
        $child = $portal->child($request->user(), $student);
        $enrollment = $portal->enrollment($child);
        $enrollments = $child->enrollments()->with('section.schoolYear', 'section.grade')->get()->sortByDesc(fn ($e) => $e->section->schoolYear->year)->unique('school_year_id');
        $audit->record('portal.reports_view', $child);

        return view('guardian.reports', compact('child', 'enrollment', 'enrollments'));
    }

    public function report(Request $request, Student $student, SchoolYear $year, GuardianPortalService $portal, InformativeReportService $reports, AuditService $audit): View|Response
    {
        abort_if($year->closed_at, 404);
        $child = $portal->child($request->user(), $student);
        $enrollment = $portal->enrollment($child);
        $results = $reports->results($child, $year);
        foreach ($results as $result) {
            $audit->record('course.result_view', $result);
        }
        $audit->record($request->routeIs('portal.report.pdf') ? 'portal.report_download' : 'portal.report_view', $child, ['school_year_id' => $year->id]);
        if ($request->routeIs('portal.report.pdf')) {
            return response($reports->pdf($child, $year, $results), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="boleta-informativa-'.$year->year.'.pdf"']);
        }

        return view('guardian.report', compact('child', 'enrollment', 'year', 'results'));
    }
}
