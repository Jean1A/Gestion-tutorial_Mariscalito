<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\ImportStudentsRequest;
use App\Jobs\ImportStudents;
use App\Models\EducationLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentImport;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SchoolReportService;
use App\Services\StudentWorkbookImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function index(): View
    {
        Gate::authorize('create', Student::class);
        Gate::authorize('create', User::class);
        Gate::authorize('manage-assignments');

        return view('students.imports', [
            'imports' => StudentImport::latest('id')->paginate(15),
            'years' => SchoolYear::available()->orderByDesc('is_active')->orderByDesc('year')->get(),
            'levels' => EducationLevel::where('is_active', true)->orderBy('name')->get(),
            'sections' => Section::with('schoolYear', 'grade.educationLevel')->where('is_active', true)
                ->whereHas('grade', fn ($query) => $query->where('is_active', true)->whereHas('educationLevel', fn ($levels) => $levels->where('is_active', true)))
                ->orderBy('school_year_id')->orderBy('grade_id')->orderBy('name')->get(),
            'headers' => StudentWorkbookImport::HEADERS,
        ]);
    }

    public function store(ImportStudentsRequest $request, AuditService $audit): RedirectResponse
    {
        $path = $request->file('file')->store('imports', 'local');
        $import = DB::transaction(function () use ($request, $path, $audit) {
            $import = StudentImport::create($request->safe()->only(['section_id', 'school_year_id', 'education_level_id']) + [
                'user_id' => $request->user()->id, 'path' => $path, 'format' => strtolower($request->file('file')->getClientOriginalExtension()),
            ]);
            $audit->record('import.created', $import, ['identity_verified' => $request->boolean('identity_verified')]);
            ImportStudents::dispatch($import->id);

            return $import;
        });

        return redirect()->route('imports.show', $import)->with('status', 'Archivo recibido en almacenamiento privado. La cola procesará la importación.');
    }

    public function show(StudentImport $import, AuditService $audit): View
    {
        Gate::authorize('view', $import);
        $audit->record('import.view', $import);
        $rows = DB::table('student_import_rows')->where('student_import_id', $import->id)->where('succeeded', false)->orderBy('row_number')->paginate(20);

        $hasAccess = Gate::allows('downloadAccess', $import)
            && DB::table('student_import_rows')->where('student_import_id', $import->id)->whereNotNull('access_payload')->exists();

        return view('students.import-result', compact('import', 'rows', 'hasAccess'));
    }

    public function template(): BinaryFileResponse
    {
        Gate::authorize('create', Student::class);
        Gate::authorize('create', User::class);
        Gate::authorize('manage-assignments');

        return response()->download(resource_path('formats/plantilla-estudiantes.xlsx'));
    }

    public function access(StudentImport $import, AuditService $audit, SchoolReportService $exports): StreamedResponse
    {
        Gate::authorize('downloadAccess', $import);
        $entries = DB::table('student_import_rows')->where('student_import_id', $import->id)->whereNotNull('access_payload')
            ->orderBy('row_number')->pluck('access_payload')->flatMap(
                fn (string $payload): array => json_decode(Crypt::decryptString($payload), true, flags: JSON_THROW_ON_ERROR),
            );
        $accounts = User::with('roles')->whereKey($entries->pluck('id')->all())->get()->keyBy('id');
        $rows = [];
        foreach ($entries as $entry) {
            $account = $accounts->get($entry['id']);
            $role = RoleName::from($entry['role']);
            if ($account?->hasRole($role) && $account->must_change_password
                && $account->session_version === $entry['version'] && $account->dni === $entry['dni']) {
                $rows[] = [$role->label(), $entry['name'], $entry['dni'], $entry['password']];
            }
        }
        abort_if($rows === [], 404, 'Las contraseñas temporales ya fueron reemplazadas o no hay cuentas nuevas en esta carga.');
        $audit->record('import.access_downloaded', $import, ['account_count' => count($rows)]);

        return $exports->xlsx(['Perfil', 'Nombre', 'DNI', 'Contraseña temporal'], $rows, 'accesos-importacion-'.$import->id.'.xlsx');
    }
}
