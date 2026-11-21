<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private AuditService $audit) {}

    public function save(array $data, ?User $subject = null, ?int $actorId = null): User
    {
        return DB::transaction(function () use ($data, $subject, $actorId) {
            // Serializar cambios de administradores para conservar al menos una cuenta operativa.
            $administratorRole = Role::where('name', RoleName::Administrator->value)->lockForUpdate()->firstOrFail();
            $old = $subject ? $subject->only(['name', 'dni', 'email', 'phone']) + ['roles' => $subject->roles()->pluck('roles.id')->all()] : null;
            if ($subject?->hasRole(RoleName::Administrator) && ! in_array($administratorRole->id, array_map('intval', $data['roles']), true)) {
                $this->ensureOtherAdministrator($subject);
            }
            $user = $subject ? User::whereKey($subject->id)->lockForUpdate()->firstOrFail() : new User;
            $fields = array_intersect_key($data, array_flip(['name', 'dni', 'email', 'phone']));
            if ($subject && ($fields['email'] ?? null) !== $subject->email) {
                $fields['email_verified_at'] = null;
            }
            if (! empty($data['password'])) {
                $fields['password'] = $data['password'];
                $fields['must_change_password'] = true;
            }
            if ($subject) {
                $fields['session_version'] = $user->session_version + 1;
            }
            if (auth()->user()?->activeRole() === RoleName::Coordinator) {
                Gate::authorize($subject ? 'update' : 'create', $subject ? $user : User::class);
                abort_if(Role::whereKey($data['roles'])->whereNotIn('name', RoleName::delegatedAccountRoles())->exists(), 403);
            }
            $user->forceFill($fields)->save();
            $user->roles()->sync($data['roles']);
            $this->audit->record($subject ? 'user.updated' : 'user.created', $user, [
                'before' => $old,
                'after' => $user->only(['name', 'dni', 'email', 'phone']) + ['roles' => array_map('intval', $data['roles'])],
                'temporary_password_changed' => ! empty($data['password']),
                'identity_verified_for_recovery' => $subject && ! empty($data['password']) && ! empty($data['identity_verified']),
            ], $actorId);

            return $user;
        });
    }

    public function setActive(User $subject, bool $active, User $actor): void
    {
        DB::transaction(function () use ($subject, $active, $actor) {
            Role::where('name', RoleName::Administrator->value)->lockForUpdate()->firstOrFail();
            $subject = User::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $subject);
            if ($active && ! $subject->roles()->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Asigna un perfil vigente a la cuenta antes de reactivarla.']);
            }
            if (! $active) {
                if ($subject->id === $actor->id) {
                    throw ValidationException::withMessages(['is_active' => 'No puedes desactivar tu propia cuenta.']);
                }
                if ($subject->hasRole(RoleName::Administrator)) {
                    $this->ensureOtherAdministrator($subject);
                }
            }
            $old = $subject->is_active;
            $subject->update(['is_active' => $active, 'session_version' => $subject->session_version + 1]);
            $this->audit->record($active ? 'user.activated' : 'user.deactivated', $subject, ['before' => $old, 'after' => $active]);
        });
    }

    public function saveStudentAccess(Student $student, #[\SensitiveParameter] string $password, ?User $account = null, ?int $actorId = null): User
    {
        if (! $student->is_active) {
            throw ValidationException::withMessages(['password' => 'Activa al estudiante antes de habilitar su acceso.']);
        }
        $account ??= new User;
        $account->forceFill([
            'student_profile_id' => $student->id,
            'name' => $student->first_name.' '.$student->last_name,
            'dni' => $student->dni,
            'password' => $password,
            'is_active' => true,
            'must_change_password' => true,
            'session_version' => ($account->session_version ?? 0) + 1,
        ])->save();
        $account->roles()->sync([Role::where('name', RoleName::Student->value)->sole()->id]);
        $this->audit->record('student.portal_credentials_saved', $student, [], $actorId);

        return $account;
    }

    private function ensureOtherAdministrator(User $subject): void
    {
        if (! User::where('is_active', true)->whereKeyNot($subject->id)->whereHas('roles', fn ($query) => $query->where('name', RoleName::Administrator->value))->exists()) {
            throw ValidationException::withMessages(['roles' => 'Debe conservarse al menos un administrador activo.']);
        }
    }
}
