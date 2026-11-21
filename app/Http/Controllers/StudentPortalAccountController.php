<?php

namespace App\Http\Controllers;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use App\Services\StudentTutoringService;
use App\Services\UserService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class StudentPortalAccountController extends Controller
{
    public function save(Request $request, Student $student, AuditService $audit, UserService $users): RedirectResponse
    {
        Gate::authorize('update', $student);
        Gate::authorize('create', User::class);
        $data = $request->validate([
            'account_action' => ['required', 'in:save,disable'],
            'password' => ['required_if:account_action,save', 'nullable', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);
        if (isset($data['password']) && (strlen($data['password']) > 72 || str_contains($data['password'], "\0"))) {
            throw ValidationException::withMessages(['password' => 'Usa una contraseña temporal de hasta 72 bytes.']);
        }

        try {
            DB::transaction(function () use ($student, $data, $audit, $users): void {
                $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
                $account = User::where('student_profile_id', $student->id)->lockForUpdate()->first();
                if ($data['account_action'] === 'disable') {
                    abort_unless($account, 404);
                    $account->update(['is_active' => false, 'session_version' => $account->session_version + 1]);
                    $audit->record('student.portal_disabled', $student);

                    return;
                }

                $users->saveStudentAccess($student, $data['password'], $account);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['password' => 'El DNI del estudiante está asociado a otra cuenta. Revisa el directorio antes de habilitarlo.']);
        }

        return back()->with('status', $data['account_action'] === 'disable' ? 'Acceso estudiantil desactivado.' : 'Acceso estudiantil guardado. El estudiante debe cambiar su contraseña al ingresar.');
    }

    public function research(Request $request, Student $student, StudentTutoringService $tutoring, AuditService $audit): RedirectResponse
    {
        Gate::authorize('student-research');
        Gate::authorize('update', $student);
        $data = $request->validate(['research_authorized' => ['required', 'boolean']]);
        $enrollment = $tutoring->activeEnrollments()->where('student_id', $student->id)->firstOrFail();
        DB::transaction(function () use ($request, $student, $data, $enrollment, $audit): void {
            SchoolYear::available()->whereKey($enrollment->school_year_id)->lockForUpdate()->firstOrFail();
            DB::table('student_research_permissions')->upsert([[
                'school_year_id' => $enrollment->school_year_id,
                'student_id' => $student->id,
                'recorded_by' => $request->user()->id,
                'authorized' => (bool) $data['research_authorized'],
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['school_year_id', 'student_id'], ['recorded_by', 'authorized', 'updated_at']);
            $audit->record('student.research_permission_updated', $student, ['authorized' => (bool) $data['research_authorized'], 'school_year_id' => $enrollment->school_year_id]);
        }, 3);

        return back()->with('status', 'Autorización de investigación actualizada.');
    }
}
