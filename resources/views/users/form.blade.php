@extends('layouts.app')
@php($accountTitle = match ($accountRole) { 'tutor' => 'tutor', 'guardian' => 'apoderado', default => 'usuario' })
@php($formTitle = ($subject->exists ? 'Editar ' : 'Crear ').$accountTitle)
@section('title', $formTitle)
@section('content')
<a href="{{ route('users.index', array_filter(['role' => $accountRole])) }}" class="back-link"><x-icon name="arrow-left"/> Volver a Gestión de usuarios</a>
<h1>{{ $formTitle }}</h1>
@if($subject->exists)
    <p class="portal-subtitle">{{ $subject->name }} · DNI {{ $subject->dni }}</p>
@endif
<section class="portal-panel mt-3">
<div class="portal-panel__heading"><x-icon name="person"/><h2>Datos de la cuenta</h2></div>
<form method="post" action="{{ $subject->exists ? route('users.update', array_filter(['user' => $subject, 'role' => $accountRole])) : route('users.store', array_filter(['role' => $accountRole])) }}">
    @csrf @if($subject->exists) @method('put') @endif
    <div class="row g-3">
        <div class="col-md-6"><label for="name" class="form-label">Nombre completo</label><input id="name" name="name" class="form-control" value="{{ old('name', $subject->name) }}" maxlength="150" required></div>
        <div class="col-md-6"><label for="dni" class="form-label">DNI propio del usuario</label><input id="dni" name="dni" class="form-control" value="{{ old('dni', $subject->dni) }}" inputmode="numeric" maxlength="8" pattern="[0-9]{8}" required></div>
        <div class="col-md-6"><label for="email" class="form-label">Correo</label><input id="email" name="email" type="email" class="form-control" value="{{ old('email', $subject->email) }}"><div class="form-text">Opcional. La recuperación por correo requerirá verificarlo.</div></div>
        <div class="col-md-6"><label for="phone" class="form-label">Teléfono</label><input id="phone" name="phone" class="form-control" value="{{ old('phone', $subject->phone) }}" inputmode="tel"></div>
        <fieldset class="col-12"><legend class="h6">Funciones autorizadas</legend>
            @foreach($roles as $availableRole)
                <div class="form-check form-check-inline"><input id="role-{{ $availableRole->id }}" type="checkbox" class="form-check-input" name="roles[]" value="{{ $availableRole->id }}" @checked(in_array($availableRole->id, old('roles', $selectedRoleIds)))><label for="role-{{ $availableRole->id }}" class="form-check-label">{{ $availableRole->label }}</label></div>
            @endforeach
        </fieldset>
        <fieldset class="col-12 border-top pt-3">
            <legend class="h6">{{ $subject->exists ? 'Restablecer contraseña' : 'Acceso inicial' }}</legend>
            <div class="row g-3">
                <div class="col-md-6"><label for="password" class="form-label">Contraseña temporal @if($subject->exists) (opcional) @endif</label><input id="password" type="password" name="password" class="form-control" autocomplete="new-password" @required(! $subject->exists)><div class="form-text">Al menos 12 caracteres, con mayúsculas, minúsculas y números.</div></div>
                <div class="col-md-6"><label for="password_confirmation" class="form-label">Confirmar contraseña temporal</label><input id="password_confirmation" type="password" name="password_confirmation" class="form-control" autocomplete="new-password" @required(! $subject->exists)></div>
                @if($subject->exists)
                    <div class="col-12">
                        <div class="form-check"><input id="identity_verified" name="identity_verified" type="checkbox" value="1" class="form-check-input"><label for="identity_verified" class="form-check-label">Verifiqué la identidad del titular antes de restablecer su contraseña.</label></div>
                    </div>
                @endif
            </div>
        </fieldset>
        <div class="col-12"><button class="btn btn-orange">Guardar</button><a href="{{ route('users.index', array_filter(['role' => $accountRole])) }}" class="btn btn-outline-secondary">Cancelar</a></div>
    </div>
</form>
</section>
@if($showTutorLinks || $showGuardianLinks)
    @include('users.links')
@endif
@endsection
