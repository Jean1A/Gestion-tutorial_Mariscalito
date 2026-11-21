<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $user->refresh()->unsetRelation('roles');
            $idleMinutes = (int) app(SettingsService::class)->required('security.idle_minutes');
            $lastActivity = (int) $request->session()->get('last_activity_at', 0);
            if (! $user->defaultRole() || ! $user->is_active || $user->trashed() || $request->session()->get('session_version') !== $user->session_version || $lastActivity + $idleMinutes * 60 <= now()->timestamp) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', 'La sesión terminó. Ingresa nuevamente.');
            }
            $request->session()->put('last_activity_at', now()->timestamp);
        }

        return $next($request);
    }
}
