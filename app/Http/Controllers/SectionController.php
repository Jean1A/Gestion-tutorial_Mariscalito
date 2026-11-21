<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Services\AccessScope;
use App\Services\AdvisorPortalService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SectionController extends Controller
{
    public function show(Request $request, Section $section, AccessScope $scope, AuditService $audit)
    {
        Gate::authorize('view', $section);
        $students = $scope->students($request->user())->whereHas('enrollments', fn ($q) => $q->where('section_id', $section->id)->where('status', 'active'))->orderBy('last_name')->paginate(25);
        foreach ($students as $student) {
            $audit->record('student.view', $student);
        }

        $courses = app(AdvisorPortalService::class)->courses($request->user(), $section->id)->get();

        return view('students.section', compact('section', 'students', 'courses'));
    }
}
