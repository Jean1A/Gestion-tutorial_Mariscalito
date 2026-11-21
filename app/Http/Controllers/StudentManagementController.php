<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveStudentRequest;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use App\Services\StudentService;
use App\Services\StudentTutoringService;
use Illuminate\Support\Facades\Gate;

class StudentManagementController extends Controller
{
    public function index(AuditService $audit)
    {
        Gate::authorize('create', Student::class);
        $students = Student::visibleInSchoolYears()
            ->with(['enrollments' => fn ($query) => $query->with('section.grade', 'section.schoolYear')->latest('starts_on')->latest('id')])
            ->withCount(['guardians as verified_guardians_count' => fn ($query) => $query->where('users.is_active', true)
                ->where('guardian_student.is_active', true)->whereNotNull('guardian_student.verified_at')])
            ->orderBy('last_name')->orderBy('id')->paginate(20);
        foreach ($students as $student) {
            $audit->record('student.view', $student);
        }

        return view('students.index', compact('students'));
    }

    public function form(AuditService $audit, StudentTutoringService $tutoring, ?Student $student = null)
    {
        $student ? Gate::authorize('update', $student) : Gate::authorize('create', Student::class);
        $subject = $student ?? new Student;
        if ($student) {
            $audit->record('student.view', $student);
        }
        $enrollment = $student?->enrollments()->orderByDesc('starts_on')->first();
        $sections = Section::with(['grade.educationLevel', 'schoolYear'])->where('is_active', true)->get();
        $portalAccount = $student && Gate::allows('create', User::class) ? User::where('student_profile_id', $student->id)->first() : null;
        $researchEnrollment = $student && Gate::allows('student-research') ? $tutoring->activeEnrollments()->where('student_id', $student->id)->first() : null;
        $researchAuthorized = $researchEnrollment && $tutoring->researchAuthorized($researchEnrollment);

        return view('students.form', compact('subject', 'sections', 'enrollment', 'portalAccount', 'researchEnrollment', 'researchAuthorized'));
    }

    public function save(SaveStudentRequest $request, StudentService $service, ?Student $student = null)
    {
        $service->save($request->validated(), $student);

        return redirect()->route('students.index')->with('status', 'Estudiante y matrícula guardados.');
    }
}
