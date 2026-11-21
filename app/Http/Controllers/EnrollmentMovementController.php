<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Services\EnrollmentMovementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EnrollmentMovementController extends Controller
{
    public function index(Request $request, EnrollmentMovementService $service): View
    {
        abort_unless($request->user()->permits('students.manage'), 403);
        $data = $request->validate(['source_id' => ['nullable', 'integer', 'exists:sections,id']]);
        $sections = Section::with('schoolYear', 'grade.educationLevel')->orderByDesc('school_year_id')->orderBy('grade_id')->orderBy('name')->get();
        $source = $sections->find($data['source_id'] ?? null);
        $roster = $source ? $service->roster($source) : collect();
        $destinations = $sections->filter(fn (Section $section): bool => $section->is_active && ! $section->schoolYear->closed_at && (! $source || ($section->id !== $source->id && $section->schoolYear->year >= $source->schoolYear->year)));

        return view('coordination.movements', compact('source', 'sections', 'destinations', 'roster'));
    }

    public function store(Request $request, EnrollmentMovementService $service): RedirectResponse
    {
        $data = $request->validate([
            'source_id' => ['required', 'integer', 'exists:sections,id'],
            'destination_id' => ['required', 'integer', 'different:source_id', 'exists:sections,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            'enrollments' => ['required', 'array', 'min:1', 'max:200'],
            'enrollments.*' => ['required', 'integer', 'distinct'],
            'overrides' => ['nullable', 'array', 'max:200'],
            'overrides.*' => ['nullable', 'integer', 'exists:sections,id'],
        ]);
        $result = $service->move($data, $request->user());

        return to_route('coordination.movements', ['source_id' => $data['source_id']])
            ->with('status', $result['applied'].' matrículas registradas. '.$result['already_processed'].' movimientos ya aplicados.');
    }
}
