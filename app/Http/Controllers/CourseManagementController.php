<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\SaveCourseRequest;
use App\Http\Requests\SaveOfficeHoursRequest;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Section;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CourseManagementController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-courses');

        return view('courses.manage', ['offerings' => CourseOffering::with('course', 'teacher', 'section.grade', 'section.schoolYear')->latest()->paginate(20), 'sections' => Section::with('grade', 'schoolYear')->where('is_active', true)->get(), 'teachers' => User::where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('name', ['teacher', 'tutor']))->get()]);
    }

    public function save(SaveCourseRequest $request, AuditService $audit, ?CourseOffering $offering = null): RedirectResponse
    {
        DB::transaction(function () use ($request, $audit, $offering) {
            $data = $request->validated();
            $teacher = User::whereKey($data['teacher_id'])->lockForUpdate()->firstOrFail();
            if (! $teacher->hasRole(RoleName::Teacher) && ! (($data['is_tutoring'] ?? false) && $teacher->hasRole(RoleName::Tutor))) {
                throw ValidationException::withMessages(['teacher_id' => 'La cuenta debe tener rol Docente.']);
            }
            $section = Section::with('schoolYear', 'grade.educationLevel')->findOrFail($data['section_id']);
            if (! $section->is_active || $section->schoolYear->closed_at || ! $section->grade->is_active || ! $section->grade->educationLevel->is_active) {
                throw ValidationException::withMessages(['section_id' => 'Selecciona una sección, grado, nivel y año activos.']);
            }
            $course = Course::firstOrCreate(['name' => $data['name']], ['icon' => $data['icon']]);
            $subject = $offering ? CourseOffering::whereKey($offering->id)->lockForUpdate()->firstOrFail() : CourseOffering::firstOrNew(['course_id' => $course->id, 'section_id' => $section->id]);
            if ($offering && ($offering->course_id !== $course->id || $offering->section_id !== $section->id)) {
                throw ValidationException::withMessages(['name' => 'Una asignación existente conserva su curso y sección para proteger el historial.']);
            }
            if (array_key_exists('is_tutoring', $data) && (bool) $data['is_tutoring'] !== (bool) $course->is_tutoring) {
                $course->update(['is_tutoring' => $data['is_tutoring']]);
                $audit->record('course.tutoring_classification_updated', $course, ['is_tutoring' => $course->is_tutoring]);
            }
            $before = $subject->exists ? $subject->only(['teacher_id', 'is_active', 'content']) : null;
            $subject->fill(['course_id' => $course->id, 'section_id' => $section->id, 'teacher_id' => $teacher->id, 'content' => $data['content'] ?? null, 'is_active' => $data['is_active']])->save();
            $audit->record('course.offering_saved', $subject, ['before' => $before, 'after' => $subject->only(['teacher_id', 'is_active', 'content'])]);
        });

        return back()->with('status', 'Curso y docente guardados.');
    }

    public function hours(SaveOfficeHoursRequest $request, AuditService $audit): RedirectResponse
    {
        $user = User::findOrFail($request->validated('user_id'));
        abort_unless($user->hasRole(RoleName::Tutor) || $user->hasRole(RoleName::Teacher), 422);
        $before = $user->office_hours;
        $user->update(['office_hours' => $request->validated('office_hours')]);
        $audit->record('staff.office_hours_updated', $user, ['before' => $before, 'after' => $user->office_hours]);

        return back()->with('status', 'Horario de atención actualizado.');
    }
}
