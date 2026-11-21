<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\SaveUserRequest;
use App\Http\Requests\SetUserStatusRequest;
use App\Models\Role;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use App\Services\AuditService;
use App\Services\UserService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    private const ROLE_LABELS = [
        'tutor' => 'Tutores',
        'teacher' => 'Docentes',
        'assistant' => 'Auxiliares',
        'guardian' => 'Apoderados',
    ];

    public function index(Request $request, AuditService $audit): View
    {
        Gate::authorize('viewAny', User::class);
        $actor = $request->user();
        $isCoordinator = $actor->activeRole() === RoleName::Coordinator;
        $roleLabels = self::ROLE_LABELS;
        if ($isCoordinator && ! $request->filled('role')) {
            return view('users.roles', compact('roleLabels'));
        }

        $roles = $this->manageableRoles($actor);
        $filters = $request->validate([
            'role' => ['nullable', Rule::in($roles->map(fn (Role $role): string => $role->name->value)->all())],
            'state' => ['nullable', 'in:active,retired'],
            'q' => ['nullable', 'string', 'max:150'],
        ]);
        $accountRole = $filters['role'] ?? null;
        $isTutorDirectory = $accountRole === RoleName::Tutor->value;
        $yearId = null;
        $sectionId = null;
        $years = collect();
        $sections = collect();
        if ($isTutorDirectory) {
            $assignmentFilters = $request->validate([
                'school_year_id' => ['nullable', 'integer', Rule::exists('school_years', 'id')->whereNull('closed_at')],
                'section_id' => [
                    'bail', 'nullable', 'integer',
                    function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                        $year = $request->integer('school_year_id');
                        if (! Section::whereKey($value)->when($year, fn ($query) => $query->where('school_year_id', $year))->exists()) {
                            $fail('Elige una sección disponible del año escolar seleccionado.');
                        }
                    },
                ],
            ]);
            $yearId = $assignmentFilters['school_year_id'] ?? null;
            $sectionId = $assignmentFilters['section_id'] ?? null;
            $years = SchoolYear::available()->orderByDesc('year')->get();
            $sections = Section::with('schoolYear', 'grade.educationLevel')
                ->when($yearId, fn ($query) => $query->where('school_year_id', $yearId))
                ->orderByDesc('school_year_id')->orderBy('grade_id')->orderBy('name')->get();
        }

        $assignmentScope = fn ($query) => $query->where('tutor_assignments.is_active', true)
            ->when($yearId, fn ($sections) => $sections->where('sections.school_year_id', $yearId))
            ->when($sectionId, fn ($sections) => $sections->where('sections.id', $sectionId));
        $state = $filters['state'] ?? 'active';
        $search = trim($filters['q'] ?? '');
        $users = User::with('roles')->whereNull('student_profile_id')
            ->when($isCoordinator, fn ($query) => $query
                ->whereHas('roles')
                ->whereDoesntHave('roles', fn ($roles) => $roles->whereNotIn('name', RoleName::delegatedAccountRoles())))
            ->where('is_active', $state === 'active')
            ->when($accountRole, fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->where('name', $accountRole)))
            ->when($search !== '', fn ($query) => $query->where(fn ($matches) => $matches->where('name', 'like', '%'.$search.'%')->orWhere('dni', 'like', $search.'%')))
            ->when($isTutorDirectory && ($yearId !== null || $sectionId !== null), fn ($query) => $query->whereHas('tutoredSections', $assignmentScope))
            ->when($isTutorDirectory, fn ($query) => $query->with([
                'tutoredSections' => fn ($sections) => $assignmentScope($sections)->with('schoolYear', 'grade.educationLevel'),
            ]))
            ->when($accountRole === RoleName::Guardian->value, fn ($query) => $query->withCount([
                'students as linked_students_count' => fn ($students) => $students->visibleInSchoolYears()->where('guardian_student.is_active', true)
                    ->whereNotNull('guardian_student.verified_at'),
            ]))
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
        foreach ($users as $user) {
            $audit->record('user.view', $user);
        }
        $canImport = $isTutorDirectory && $actor->can('create', User::class)
            && $actor->can('manage-assignments') && $actor->can('coordinate-tutors');

        return view('users.index', compact('users', 'roles', 'roleLabels', 'accountRole', 'state', 'search', 'canImport', 'isCoordinator', 'years', 'sections', 'yearId', 'sectionId'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', User::class);

        return $this->form($request, new User);
    }

    public function store(SaveUserRequest $request, UserService $service): RedirectResponse
    {
        $user = $service->save($request->validated());
        $role = $this->selectedRole($request);
        if (in_array($role, [RoleName::Tutor->value, RoleName::Guardian->value], true)
            && $user->hasRole(RoleName::from($role)) && $request->user()->can('manage-assignments')) {
            return to_route('users.edit', ['user' => $user, 'role' => $role])
                ->with('status', 'Cuenta creada. Ahora puedes registrar su sección o sus estudiantes vinculados.');
        }

        return $this->directoryRedirect($request)->with('status', 'Usuario creado. Debe cambiar su contraseña en el primer ingreso.');
    }

    public function edit(Request $request, User $user, AuditService $audit): View
    {
        Gate::authorize('update', $user);
        $audit->record('user.view', $user);

        return $this->form($request, $user->load('roles'));
    }

    public function update(SaveUserRequest $request, User $user, UserService $service): RedirectResponse
    {
        $service->save($request->validated(), $user);

        return $this->directoryRedirect($request)->with('status', 'Usuario actualizado. Sus sesiones anteriores fueron invalidadas.');
    }

    public function status(SetUserStatusRequest $request, User $user, UserService $service): RedirectResponse
    {
        $active = $request->boolean('is_active');
        $service->setActive($user, $active, $request->user());

        return $this->directoryRedirect($request)->with('status', $active ? 'Cuenta reactivada.' : 'Cuenta retirada. Su historial se conserva.');
    }

    /** @return Collection<int, Role> */
    private function manageableRoles(User $actor): Collection
    {
        return Role::whereIn('name', array_diff(array_column(RoleName::cases(), 'value'), [RoleName::Student->value]))
            ->when($actor->activeRole() === RoleName::Coordinator, fn ($query) => $query->whereIn('name', RoleName::delegatedAccountRoles()))
            ->orderBy('label')->get();
    }

    private function form(Request $request, User $subject): View
    {
        $roles = $this->manageableRoles($request->user());
        $accountRole = $this->selectedRole($request);
        $selectedRoleIds = $subject->exists ? $subject->roles->modelKeys()
            : $roles->filter(fn (Role $role): bool => $role->name->value === $accountRole)->modelKeys();
        $canLink = $subject->exists && $subject->is_active && $request->user()->can('manage-assignments');
        $showTutorLinks = $canLink && $subject->hasRole(RoleName::Tutor) && $request->user()->can('coordinate-tutors');
        $showGuardianLinks = $canLink && $subject->hasRole(RoleName::Guardian);
        $sections = $showTutorLinks
            ? Section::with('schoolYear', 'grade.educationLevel')->where('is_active', true)->orderByDesc('school_year_id')->orderBy('grade_id')->orderBy('name')->get()
            : collect();
        $tutorSections = $showTutorLinks ? $subject->tutoredSections()->wherePivot('is_active', true)->with('schoolYear', 'grade.educationLevel')->get() : collect();
        $linkedStudents = $showGuardianLinks ? $subject->students()->visibleInSchoolYears()->wherePivot('is_active', true)->orderBy('last_name')->get() : collect();

        return view('users.form', compact('subject', 'roles', 'accountRole', 'selectedRoleIds', 'showTutorLinks', 'showGuardianLinks', 'sections', 'tutorSections', 'linkedStudents'));
    }

    private function selectedRole(Request $request): ?string
    {
        $role = $request->query('role');

        if (! is_string($role) || ! RoleName::tryFrom($role) || $role === RoleName::Student->value) {
            return null;
        }
        if ($request->user()->activeRole() === RoleName::Coordinator && ! in_array($role, RoleName::delegatedAccountRoles(), true)) {
            return null;
        }

        return $role;
    }

    private function directoryRedirect(Request $request): RedirectResponse
    {
        return to_route('users.index', array_filter(['role' => $this->selectedRole($request)]));
    }
}
