<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class LoginService
{
    private const FAILED_ATTEMPT_TTL = 15 * 60;

    public function __construct(private SettingsService $settings, private AuditService $audit) {}

    public function lockSecondsRemaining(string $dni): int
    {
        $lockedUntil = (int) Cache::get($this->accountKey($dni).':until', 0);

        return max(0, $lockedUntil - now()->timestamp);
    }

    public function login(LoginRequest $request, ?RoleName $profile = null): void
    {
        $limit = (int) $this->settings->required('security.login_attempts');
        $lockSeconds = (int) $this->settings->required('security.lock_minutes') * 60;
        $dni = $request->validated('dni');
        $key = $this->accountKey($dni);

        try {
            Cache::lock($key.':attempt', 30)->block(3, function () use ($request, $profile, $dni, $key, $limit, $lockSeconds): void {
                $remaining = $this->lockSecondsRemaining($dni);
                if ($remaining > 0) {
                    $this->rejectLocked($request, $dni, $remaining);
                }

                if (! Auth::attemptWhen(
                    $request->only('dni', 'password') + ['is_active' => true],
                    fn (User $user): bool => $profile === null || $user->hasRole($profile),
                )) {
                    $failures = (int) Cache::get($key, 0) + 1;
                    if ($failures >= $limit) {
                        Cache::put($key.':until', now()->timestamp + $lockSeconds, $lockSeconds);
                        Cache::forget($key);
                        $this->rejectLocked($request, $dni, $lockSeconds);
                    }

                    Cache::put($key, $failures, self::FAILED_ATTEMPT_TTL);
                    throw ValidationException::withMessages([
                        'dni' => $profile ? 'Verifica tu DNI, contraseña y el perfil elegido.' : 'Los datos de acceso no son válidos.',
                    ]);
                }

                Cache::forget($key);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['dni' => 'Hay un intento en curso para este DNI. Vuelve a intentarlo.']);
        }

        $user = Auth::user();
        $role = $profile ?? $user->defaultRole();
        if (! $role) {
            Auth::logout();
            throw ValidationException::withMessages(['dni' => 'La cuenta no tiene una función habilitada. Contacta al colegio.']);
        }

        $request->session()->forget('login.blocked_dni');
        $request->session()->regenerate();
        $request->session()->put([
            'active_role' => $role->value,
            'session_version' => $user->session_version,
            'last_activity_at' => now()->timestamp,
        ]);
        $this->audit->record('auth.login', $user);
    }

    private function accountKey(string $dni): string
    {
        return 'school-login:'.hash('sha256', $dni);
    }

    private function rejectLocked(LoginRequest $request, string $dni, int $seconds): never
    {
        $request->session()->put('login.blocked_dni', $dni);

        throw ValidationException::withMessages([
            'dni' => "Acceso bloqueado temporalmente. Intenta nuevamente en {$seconds} segundos.",
        ]);
    }
}
