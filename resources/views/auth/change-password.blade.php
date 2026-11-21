@extends('layouts.guest')
@section('title', 'Cambiar contraseña')
@section('content')
<div class="account-form">
    <h1 class="h3">Cambia tu contraseña</h1>
    <p>Antes de continuar, reemplaza tu contraseña temporal. Usa al menos 12 caracteres, mayúsculas, minúsculas y números.</p>
    <form method="post" action="{{ route('password.change.store') }}">
        @csrf
        <label for="current_password" class="form-label">Contraseña actual</label>
        <input id="current_password" class="form-control mb-3" name="current_password" type="password" autocomplete="current-password" required>
        <label for="password" class="form-label">Nueva contraseña</label>
        <input id="password" class="form-control mb-3" name="password" type="password" autocomplete="new-password" required>
        <label for="password_confirmation" class="form-label">Repite la nueva contraseña</label>
        <input id="password_confirmation" class="form-control mb-3" name="password_confirmation" type="password" autocomplete="new-password" required>
        <button class="btn btn-orange w-100">Guardar contraseña</button>
    </form>
</div>
@endsection
