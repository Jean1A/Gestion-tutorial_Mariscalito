@extends('layouts.guest')
@section('title', 'Nueva contraseña')
@section('content')
<x-back-link :href="route('login')">Volver al ingreso</x-back-link>
<div class="account-form">
    <h1 class="h3">Elige una nueva contraseña</h1>
    <form method="post" action="{{ route('password.update') }}">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="form-label" for="email">Correo verificado</label>
        <input id="email" class="form-control mb-3" name="email" type="email" value="{{ old('email') }}" required>
        <label class="form-label" for="password">Nueva contraseña</label>
        <input id="password" class="form-control mb-3" name="password" type="password" autocomplete="new-password" required>
        <label class="form-label" for="password_confirmation">Repite la nueva contraseña</label>
        <input id="password_confirmation" class="form-control mb-3" name="password_confirmation" type="password" autocomplete="new-password" required>
        <button class="btn btn-orange w-100">Guardar</button>
    </form>
</div>
@endsection
