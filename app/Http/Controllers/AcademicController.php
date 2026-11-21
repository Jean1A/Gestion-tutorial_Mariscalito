<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveAcademicRequest;
use App\Models\EducationLevel;
use App\Models\Grade;
use App\Models\SchoolYear;
use App\Services\AcademicService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AcademicController extends Controller
{
    public function index(string $resource)
    {
        Gate::authorize('manage-academics');
        $definition = config('school-resources.'.$resource);
        abort_unless($definition, 404);
        $rows = $definition['model']::orderByDesc('id')->paginate(20);

        return view('academics.index', compact('resource', 'definition', 'rows'));
    }

    public function form(string $resource, ?int $id = null)
    {
        Gate::authorize('manage-academics');
        $definition = config('school-resources.'.$resource);
        abort_unless($definition, 404);
        $subject = $id ? $definition['model']::findOrFail($id) : new $definition['model'];
        $options = [
            'school_year_id' => SchoolYear::available()->orderByDesc('year')->pluck('year', 'id'),
            'education_level_id' => EducationLevel::pluck('name', 'id'),
            'grade_id' => Grade::with('educationLevel')->get()->mapWithKeys(fn ($g) => [$g->id => $g->educationLevel->name.' · '.$g->name]),
        ];

        return view('academics.form', compact('resource', 'definition', 'subject', 'options'));
    }

    public function changeYearStatus(Request $request, SchoolYear $year): RedirectResponse
    {
        Gate::authorize('manage-academics');
        $data = $request->validate(['action' => ['required', 'in:close,reopen'], 'reason' => ['required', 'string', 'min:8', 'max:500']]);
        DB::transaction(function () use ($year, $data): void {
            $year = SchoolYear::whereKey($year->id)->lockForUpdate()->firstOrFail();
            $closing = $data['action'] === 'close';
            if ($closing ? ! $year->is_active : ! $year->closed_at) {
                throw ValidationException::withMessages(['action' => 'El estado del año cambió. Recarga la página.']);
            }
            $year->forceFill(['is_active' => false, 'closed_at' => $closing ? now() : null])->save();
            app(AuditService::class)->record($closing ? 'academic.year_closed' : 'academic.year_reopened', $year, ['reason' => $data['reason']]);
        });

        return to_route('academics.index', 'years')->with('status', 'Estado del año actualizado.');
    }

    public function save(SaveAcademicRequest $request, AcademicService $service, string $resource, ?int $id = null)
    {
        $service->save($resource, $request->validated(), $id);

        return redirect()->route('academics.index', $resource)->with('status', 'Configuración académica guardada.');
    }
}
