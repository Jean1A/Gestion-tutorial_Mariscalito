<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Services\AuditService;
use App\Services\LoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(Request $request, LoginService $service, ?RoleName $role = null): View
    {
        if ($role === null) {
            return view('auth.profiles');
        }

        $oldDni = $request->old('dni');
        $lastDni = $oldDni ?? $request->session()->get('login.blocked_dni');
        $lockSeconds = is_string($lastDni) ? $service->lockSecondsRemaining($lastDni) : 0;
        if ($lockSeconds === 0) {
            $request->session()->forget('login.blocked_dni');
        }
        $dni = $lockSeconds > 0 ? $lastDni : (is_string($oldDni) ? $oldDni : '');

        return view('auth.login', compact('role', 'dni', 'lockSeconds'));
    }

    public function store(LoginRequest $request, LoginService $service, ?RoleName $role = null): RedirectResponse
    {
        $service->login($request, $role);

        return redirect()->route($request->user()->must_change_password ? 'password.change' : 'dashboard');
    }

    public function editPassword()
    {
        return view('auth.change-password');
    }

    public function updatePassword(ChangePasswordRequest $request, AuditService $audit)
    {
        DB::transaction(function () use ($request, $audit) {
            $user = $request->user();
            $user->update(['password' => $request->validated('password'), 'must_change_password' => false, 'session_version' => $user->session_version + 1]);
            $audit->record('auth.password_changed', $user);
            $request->session()->regenerate();
            $request->session()->put('session_version', $user->session_version);
        });

        return redirect()->route('dashboard')->with('status', 'Tu contraseña fue actualizada.');
    }

    public function destroy(Request $request, AuditService $audit)
    {
        $audit->record('auth.logout', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
