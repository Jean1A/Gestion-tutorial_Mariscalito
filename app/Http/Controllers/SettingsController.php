<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\SaveRolePermissionsRequest;
use App\Http\Requests\SaveSettingsRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function index()
    {
        Gate::authorize('manage-settings');

        return view('settings.index', ['settings' => Setting::all()->pluck('value', 'key'), 'roles' => Role::with('permissions')->whereIn('name', array_column(RoleName::cases(), 'value'))->get(), 'permissions' => Permission::all()]);
    }

    public function save(SaveSettingsRequest $request, AuditService $audit)
    {
        DB::transaction(function () use ($request, $audit) {
            $map = ['login_attempts' => 'security.login_attempts', 'lock_minutes' => 'security.lock_minutes', 'idle_minutes' => 'security.idle_minutes', 'edit_hours' => 'attendance.edit_hours', 'consecutive_days' => 'attendance.consecutive_days', 'percentage_thresholds' => 'attendance.percentage_thresholds'];
            $before = Setting::whereIn('key', array_values($map))->lockForUpdate()->get()->pluck('value', 'key')->all();
            $after = [];
            foreach ($map as $field => $key) {
                $value = $field === 'percentage_thresholds' ? array_map('floatval', $request->validated($field)) : (int) $request->validated($field);
                if (is_array($value)) {
                    sort($value);
                } $after[$key] = $value;
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
            $audit->record('settings.updated', $request->user(), ['before' => $before, 'after' => $after]);
        });

        return back()->with('status', 'Parámetros actualizados sin modificar código.');
    }

    public function permissions(SaveRolePermissionsRequest $request, Role $role, AuditService $audit)
    {
        $ids = $request->validated('permissions') ?? [];
        $names = Permission::whereIn('id', $ids)->pluck('name')->all();
        if (array_diff($names, config('school.role_permission_ceiling.'.$role->name->value, []))) {
            throw ValidationException::withMessages(['permissions' => 'El permiso excede el ámbito permitido para este rol.']);
        }
        if ($role->name === RoleName::Administrator && array_diff(['users.manage', 'settings.manage', 'students.view'], $names)) {
            throw ValidationException::withMessages(['permissions' => 'El administrador debe conservar gestión de usuarios, configuración y consulta básica.']);
        }
        DB::transaction(function () use ($role, $ids, $audit) {
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $before = $role->permissions()->pluck('permissions.id')->all();
            $role->permissions()->sync($ids);
            $audit->record('role.permissions_updated', $role, ['before' => $before, 'after' => $ids]);
        });

        return back()->with('status', 'Permisos del rol actualizados. El ámbito de datos sigue siendo obligatorio.');
    }
}
