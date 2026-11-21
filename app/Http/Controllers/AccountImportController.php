<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use App\Services\AccountImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountImportController extends Controller
{
    public function create(string $role): View
    {
        $this->authorizeTutorImport($role);
        $years = SchoolYear::available()->orderByDesc('is_active')->orderByDesc('year')->get();
        $sections = Section::with('schoolYear', 'grade.educationLevel')->where('is_active', true)->orderBy('name')->get();

        return view('users.import', compact('years', 'sections') + ['columns' => AccountImportService::COLUMNS]);
    }

    public function template(string $role): BinaryFileResponse
    {
        $this->authorizeTutorImport($role);

        return response()->download(resource_path('formats/plantilla-tutores.xlsx'));
    }

    public function store(Request $request, string $role, AccountImportService $imports): RedirectResponse
    {
        $this->authorizeTutorImport($role);
        $data = $request->validate([
            'school_year_id' => ['required', 'integer', Rule::exists('school_years', 'id')->whereNull('closed_at')],
            'file' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:5120'],
        ]);
        $result = $imports->import($data['file'], (int) $data['school_year_id'], $request->user());

        return to_route('users.index', ['role' => $role])->with('status', $result['accounts'].' cuentas creadas y '.$result['links'].' vínculos registrados.');
    }

    private function authorizeTutorImport(string $role): void
    {
        abort_unless($role === RoleName::Tutor->value, 404);
        Gate::authorize('create', User::class);
        Gate::authorize('manage-assignments');
        Gate::authorize('coordinate-tutors');
    }
}
