<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignmentRequest;
use App\Models\Grade;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Services\AssignmentService;
use Illuminate\Support\Facades\Gate;

class AssignmentController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-assignments');

        return view('academics.assignments', ['sections' => Section::with('schoolYear', 'grade.educationLevel')->get(), 'grades' => Grade::with('educationLevel')->get(), 'years' => SchoolYear::available()->orderByDesc('year')->get()]);
    }

    public function save(AssignmentRequest $request, AssignmentService $service)
    {
        $service->save($request->validated(), $request->user());

        return back()->with('status', 'Vínculo guardado y auditado. La desactivación conserva el historial.');
    }
}
