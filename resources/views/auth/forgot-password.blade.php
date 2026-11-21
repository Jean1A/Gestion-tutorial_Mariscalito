@extends('layouts.guest')
@section('title', 'Recuperar contraseña')
@section('content')
<x-back-link :href="route('login')">Volver al ingreso</x-back-link>
<div class="account-form">
    <h1 class="h3">Recuperar contraseña</h1>
    <p>Ingresa tu correo verificado. Si no tienes uno, el colegio puede restablecer tu acceso después de verificar tu identidad.</p>
    <form method="post" action="{{ route('password.email') }}">@csrf
        <label class="form-label" for="email">Correo verificado</label>
        <input id="email" class="form-control mb-3" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required>
        <button class="btn btn-orange w-100">Solicitar enlace</button>
    </form>
</div>
@endsection
