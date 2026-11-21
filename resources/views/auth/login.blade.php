@extends('layouts.guest')
@php($isStudent = $role === \App\Enums\RoleName::Student)
@section('title', 'Iniciar sesión · '.$role->label())
@section('guest-feedback', '')
@section('content')
<x-back-link :href="route('login')">Cambiar perfil</x-back-link>
<div class="welcome-heading">
    <span class="status-pill status-pill--info login-role">{{ $role->label() }}</span>
    <h1>Iniciar sesión</h1>
    <p>Ingresa tu DNI y contraseña para continuar.</p>
</div>
@if($lockSeconds > 0)
    <x-notice tone="error" title="Acceso temporalmente bloqueado" data-login-lockout="{{ $lockSeconds }}">
        <p>Espera <span data-login-lockout-seconds role="timer">{{ $lockSeconds }}</span> s para volver a intentar con este DNI.</p>
    </x-notice>
@else
    <x-feedback error-title="No pudimos iniciar sesión"/>
@endif
<form id="login-credentials" method="post" action="{{ route('login.store', $role->value) }}" @unless($isStudent) data-login-form @endunless>
    @csrf
    <label class="form-label" for="dni">Usuario (DNI)</label>
    <div class="login-input">
        <input id="dni" name="dni" value="{{ $dni }}" inputmode="numeric" autocomplete="username" minlength="8" maxlength="8" pattern="[0-9]{8}" placeholder="8 dígitos" required aria-describedby="dni-help">
    </div>
    <label class="form-label mt-3" for="password">Contraseña</label>
    <div class="login-input">
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="button" class="password-toggle" data-toggle-password="password" aria-label="Mostrar contraseña" aria-pressed="false"><x-icon name="eye"/></button>
    </div>
    <div class="login-options">
        @unless($isStudent)<label><input type="checkbox" id="remember-dni" class="form-check-input"> Recordar mi DNI</label>@endunless
        <a class="portal-action" href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
    </div>
    <button class="btn btn-orange login-submit" type="submit">Iniciar sesión</button>
    <p class="login-help" id="dni-help">
        @if($isStudent)
            En una computadora compartida, cierra sesión cuando termines.
        @elseif($role === \App\Enums\RoleName::Guardian)
            Usa tu propio DNI. El colegio verifica el vínculo con tus hijos.
        @else
            Usa el DNI que registraste en el colegio.
        @endif
    </p>
</form>
@endsection
