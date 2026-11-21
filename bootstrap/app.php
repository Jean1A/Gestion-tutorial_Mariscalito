<?php

use App\Exceptions\SetupIncomplete;
use App\Http\Middleware\EnsureAccountSession;
use App\Http\Middleware\RequireGuardianConsent;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SecurityHeaders::class]);
        $middleware->alias([
            'account.session' => EnsureAccountSession::class,
            'password.changed' => RequirePasswordChange::class,
            'guardian.consent' => RequireGuardianConsent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['private_content', 'private_feedback', 'private_note', 'forum_text', 'student_answers']);
        $exceptions->render(function (SetupIncomplete $exception, Request $request) {
            return response()->view('errors.setup', [], 503);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
