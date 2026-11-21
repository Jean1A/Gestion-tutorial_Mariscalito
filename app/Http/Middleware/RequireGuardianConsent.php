<?php

namespace App\Http\Middleware;

use App\Enums\RoleName;
use App\Services\ConsentService;
use Closure;
use Illuminate\Http\Request;

class RequireGuardianConsent
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()->activeRole() === RoleName::Guardian && ! app(ConsentService::class)->hasValidConsent($request->user())) {
            return redirect()->route('privacy.consent');
        }

        return $next($request);
    }
}
