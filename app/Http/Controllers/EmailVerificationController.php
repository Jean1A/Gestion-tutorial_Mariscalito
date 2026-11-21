<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function notice()
    {
        return view('auth.verify-email');
    }

    public function send(Request $request)
    {
        abort_unless($request->user()->email, 422, 'No tienes un correo registrado. Contacta al administrador.');
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Si el correo requiere verificación, recibirás un enlace.');
    }

    public function verify(EmailVerificationRequest $request, AuditService $audit)
    {
        $request->fulfill();
        $audit->record('auth.email_verified', $request->user());

        return redirect()->route('dashboard')->with('status', 'Correo verificado. Ya puedes usarlo para recuperar tu contraseña.');
    }
}
