<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\CourseFilterRequest;
use App\Http\Requests\SaveCourseContentRequest;
use App\Http\Requests\SaveCourseRecordRequest;
use App\Http\Requests\SaveCourseResultRequest;
use App\Models\AcademicPeriod;
use App\Models\AttendanceRecord;
use App\Models\CourseAttendanceSession;
use App\Models\CourseOffering;
use App\Services\AccessScope;
use App\Services\AuditService;
use App\Services\CourseRecordService;
use App\Services\SchoolReportService;
use App\Services\SpreadsheetRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseRecordController extends Controller
{
    public function resultTemplate(CourseOffering $offering): Response
    {
        Gate::authorize('grade', $offering);

        return response("dni,period,achievement,is_published\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="plantilla-logros.csv"']);
    }

    public function exportResults(Request $request, CourseOffering $offering, SchoolReportService $reports, AuditService $audit): StreamedResponse
    {
        Gate::authorize('exportResults', $offering);
        $data = $request->validate(['period_id' => ['nullable', 'integer', 'exists:academic_periods,id']]);
        $periodId = $data['period_id'] ?? null;
        abort_if($periodId && ! AcademicPeriod::whereKey($periodId)->where('school_year_id', $offering->section->school_year_id)->exists(), 422);
        $results = $offering->results()->with('enrollment.student', 'period')
            ->when($periodId, fn ($query) => $query->where('academic_period_id', $periodId))
            ->orderBy('id')->get();
        $audit->record('course.results_exported', $offering, ['period_id' => $periodId, 'rows' => $results->count()]);

        return $reports->xlsx(
            ['DNI', 'Estudiante', 'Curso', 'Período', 'Logro', 'Estado'],
            $results->map(fn ($result) => [$result->enrollment->student?->dni, $result->enrollment->student?->full_name, $offering->course->name, $result->period->name, $result->achievement, $result->is_published ? 'Publicado' : 'Borrador']),
            'logros-curso-'.$offering->id.'.xlsx',
        );
    }

    public function importResults(Request $request, CourseOffering $offering, CourseRecordService $service): RedirectResponse
    {
        Gate::authorize('grade', $offering);
        $request->validate(['file' => ['required', 'file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120']]);
        $file = $request->file('file');
        $count = DB::transaction(function () use ($file, $offering, $request, $service): int {
            $number = 0;
            $seen = [];
            foreach (app(SpreadsheetRows::class)->read($file->getRealPath(), strtolower($file->getClientOriginalExtension())) as $values) {
                $number++;
                if ($number === 1) {
                    $header = array_map(fn ($v) => trim(ltrim((string) $v, "\xEF\xBB\xBF")), $values);
                    if ($header !== ['dni', 'period', 'achievement', 'is_published']) {
                        throw ValidationException::withMessages(['file' => 'Usa la plantilla dni,period,achievement,is_published.']);
                    }

                    continue;
                }
                if ($number > 2001 || count($values) !== 4) {
                    throw ValidationException::withMessages(['file' => 'Límite de 2000 filas y cuatro columnas.']);
                }
                [$dni, $periodName, $achievement, $published] = array_map(fn ($v) => trim((string) $v), $values);
                $enrollment = $offering->section->enrollments()->whereHas('student', fn ($q) => $q->where('dni', $dni))->latest('starts_on')->first();
                $period = AcademicPeriod::where('school_year_id', $offering->section->school_year_id)->where('name', $periodName)->where('is_active', true)->first();
                $key = $dni.'|'.$periodName;
                if (! $enrollment || ! $period || ! in_array($achievement, ['AD', 'A', 'B', 'C'], true) || ! in_array($published, ['0', '1'], true) || isset($seen[$key])) {
                    throw ValidationException::withMessages(['file' => 'Revisa la fila '.$number.': matrícula del curso, nombre del período, logro y publicación 0 o 1, sin duplicados. No se guardó ninguna fila.']);
                }
                $seen[$key] = true;
                $service->result($offering, ['enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id, 'achievement' => $achievement, 'is_published' => $published === '1'], $request->user());
            }
            if ($number < 2) {
                throw ValidationException::withMessages(['file' => 'Agrega al menos un resultado a la plantilla.']);
            }

            return $number - 1;
        });

        return back()->with('status', $count.' resultados importados y auditados.');
    }

    public function index(Request $request): View
    {
        abort_unless($request->user()->permits('courses.record'), 403);

        $query = CourseOffering::with('course', 'teacher', 'section.grade', 'section.schoolYear')->where('is_active', true)->whereHas('course', fn ($q) => $q->where('is_active', true));
        if (in_array($request->user()->activeRole(), [RoleName::Tutor, RoleName::Assistant], true)) {
            $query->whereIn('section_id', app(AccessScope::class)->sections($request->user())->select('sections.id'));
        } else {
            $query->where('teacher_id', $request->user()->id);
        }

        return view('courses.mine', ['courses' => $query->get()]);
    }

    public function edit(CourseFilterRequest $request, CourseOffering $offering, CourseRecordService $service, AuditService $audit): View
    {
        Gate::authorize('view', $offering);
        abort_if($request->user()->activeRole() === RoleName::Guardian, 403);
        $validated = $request->validated();
        $date = $validated['date'] ?? now()->toDateString();
        $session = CourseAttendanceSession::where('course_offering_id', $offering->id)->whereHas('schoolDay', fn ($q) => $q->whereDate('date', $date))->first();
        $records = $session?->records()->get()->keyBy('enrollment_id') ?? collect();
        $roster = $service->roster($offering, $date)->get()->sortBy(fn ($e) => $e->student->full_name);
        $periods = AcademicPeriod::where('school_year_id', $offering->section->school_year_id)->where('is_active', true)->get();
        $results = $request->user()->can('grade', $offering) ? $offering->results()->with('enrollment.student', 'period')->latest()->limit(50)->get() : collect();
        $daily = AttendanceRecord::whereIn('enrollment_id', $roster->pluck('id'))
            ->whereHas('session.schoolDay', fn ($query) => $query->whereDate('date', $date)->where('is_instructional', true))->get()->keyBy('enrollment_id');
        $audit->record('course.roster_view', $offering);
        foreach ($results as $result) {
            $audit->record('course.result_view', $result);
        }

        return view('courses.record', compact('offering', 'date', 'session', 'records', 'roster', 'periods', 'results', 'daily'));
    }

    public function save(SaveCourseRecordRequest $request, CourseOffering $offering, CourseRecordService $service): RedirectResponse
    {
        $service->save($offering, $request->validated(), $request->user());

        return back()->with('status', 'Asistencia por curso guardada y auditada.');
    }

    public function result(SaveCourseResultRequest $request, CourseOffering $offering, CourseRecordService $service): RedirectResponse
    {
        $service->result($offering, $request->validated(), $request->user());

        return back()->with('status', 'Nivel de logro guardado. Solo los publicados se muestran al apoderado.');
    }

    public function content(SaveCourseContentRequest $request, CourseOffering $offering, AuditService $audit): RedirectResponse
    {
        $before = $offering->content;
        $offering->update(['content' => $request->validated('content')]);
        $audit->record('course.content_updated', $offering, ['before' => $before, 'after' => $offering->content]);

        return back()->with('status', 'Contenido del curso actualizado.');
    }
}
