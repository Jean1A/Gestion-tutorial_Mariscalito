<?php

namespace App\Http\Controllers;

use App\Http\Requests\PasswordRecoveryRequest;
use App\Services\PasswordRecoveryService;
use Illuminate\Http\Request;

class PasswordRecoveryController extends Controller
{
    public function create()
    {
        return view('auth.forgot-password');
    }

    public function store(PasswordRecoveryRequest $request, PasswordRecoveryService $service)
    {
        $service->requestLink($request->validated('email'));

        return back()->with('status', 'Si el correo está verificado y la cuenta está activa, recibirás un enlace para recuperar tu contraseña. Si no tienes correo verificado, contacta al administrador del colegio.');
    }

    public function edit(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token]);
    }

    public function update(PasswordRecoveryRequest $request, PasswordRecoveryService $service)
    {
        if (! $service->reset($request->validated())) {
            return back()->withErrors(['email' => 'El enlace no es válido o ha vencido. Solicita uno nuevo.']);
        }

        return redirect()->route('login')->with('status', 'Contraseña actualizada. Ingresa nuevamente.');
    }
}
