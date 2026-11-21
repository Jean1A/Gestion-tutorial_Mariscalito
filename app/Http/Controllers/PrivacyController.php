<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\ConsentRequest;
use App\Services\AuditService;
use App\Services\ConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PrivacyController extends Controller
{
    public function externalNotifications(Request $request, AuditService $audit): RedirectResponse
    {
        abort_unless($request->user()->can('guardian-portal'), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $request->user()->forceFill(['external_notifications_enabled' => (bool) $data['enabled']])->save();
        $audit->record('privacy.external_notifications_changed', $request->user(), ['enabled' => (bool) $data['enabled']]);

        return back()->with('status', 'Preferencia de avisos externos guardada. La entrega requiere un proveedor configurado.');
    }

    public function policy()
    {
        return view('privacy.policy');
    }

    public function show(Request $request, ConsentService $service, AuditService $audit)
    {
        abort_unless($request->user()->activeRole() === RoleName::Guardian, 403);
        $children = $request->user()->students()->wherePivot('is_active', true)->wherePivotNotNull('verified_at')->get();
        foreach ($children as $child) {
            $audit->record('privacy.link_view', $child);
        }

        return view('privacy.consent', ['children' => $children, 'current' => $service->current($request->user())]);
    }

    public function accept(ConsentRequest $request, ConsentService $service)
    {
        $service->accept($request->user());

        return redirect()->route('dashboard')->with('status', 'Consentimiento registrado. Puedes retirarlo desde la política de privacidad.');
    }

    public function withdraw(Request $request, ConsentService $service)
    {
        abort_unless($request->user()->activeRole() === RoleName::Guardian, 403);
        $service->withdraw($request->user());

        return redirect()->route('privacy.consent')->with('status', 'Consentimiento retirado. Puedes continuar coordinando con el colegio por sus canales habituales.');
    }
}
