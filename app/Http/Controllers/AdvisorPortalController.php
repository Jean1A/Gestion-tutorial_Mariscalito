<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdvisorFilterRequest;
use App\Models\Student;
use App\Services\AdvisorPortalService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdvisorPortalController extends Controller
{
    public function sections(AdvisorFilterRequest $request, AdvisorPortalService $portal, AuditService $audit): View
    {
        $sections = $portal->sections($request->user())->withCount(['enrollments' => fn ($q) => $q->where('status', 'active')->whereHas('student', fn ($s) => $s->where('is_active', true))])->orderByDesc('school_year_id')->paginate(12);
        $audit->record('advisor.sections_view', $request->user());

        return view('advisor.sections', compact('sections'));
    }

    public function students(AdvisorFilterRequest $request, AdvisorPortalService $portal, AuditService $audit): View
    {
        $sectionId = $request->selectedSection();
        $sections = $portal->sections($request->user())->get();
        $students = $portal->students($request->user(), $sectionId, $request->validated('q'))->with(['enrollments' => fn ($q) => $q->whereIn('section_id', $portal->sections($request->user())->select('sections.id'))->with('section.grade', 'section.schoolYear')])->orderBy('last_name')->paginate(20)->withQueryString();
        foreach ($students as $student) {
            $audit->record('advisor.student_list_view', $student);
        }

        return view('advisor.students', compact('students', 'sections', 'sectionId'));
    }

    public function student(Request $request, Student $student, AdvisorPortalService $portal, AuditService $audit): View
    {
        $data = $portal->studentData($request->user(), $student);
        $audit->record('advisor.student_view', $student);
        foreach ($data['history'] as $record) {
            $audit->record('course.attendance_view', $record);
        }
        foreach ($data['results'] as $result) {
            $audit->record('course.result_view', $result);
        }

        return view('advisor.student', $data);
    }

    public function alerts(AdvisorFilterRequest $request, AdvisorPortalService $portal, AuditService $audit): View
    {
        $sectionId = $request->selectedSection();
        $sections = $portal->sections($request->user())->get();
        $alerts = $portal->alerts($request->user(), $sectionId)->latest('updated_at')->paginate(20)->withQueryString();
        foreach ($alerts as $alert) {
            $audit->record('advisor.alert_view', $alert);
        }

        return view('advisor.alerts', compact('alerts', 'sections', 'sectionId'));
    }
}
