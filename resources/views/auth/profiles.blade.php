@extends('layouts.guest')
@section('title', 'Elegir perfil · MARISCALITO')
@section('panel-class', 'auth-panel--profiles')
@php
    use App\Enums\RoleName;

    $profiles = [
        [RoleName::Student, 'mortarboard'],
        [RoleName::Guardian, 'people'],
        [RoleName::Tutor, 'book'],
        [RoleName::Teacher, 'palette'],
        [RoleName::Assistant, 'check-circle'],
        [RoleName::Coordinator, 'bar-chart'],
        [RoleName::Administrator, 'lock'],
    ];
@endphp
@section('content')
<div class="welcome-heading">
    <h1>¿Con qué perfil ingresas?</h1>
    <p>Selecciona tu perfil para iniciar sesión en Mariscalito.</p>
</div>
<nav class="login-profile-grid" aria-label="Perfiles de acceso">
    @foreach($profiles as [$role, $icon])
        <a class="login-profile-card {{ $loop->last ? 'login-profile-card--last' : '' }}" href="{{ route('login', $role->value) }}">
            <span class="course-symbol"><x-icon :name="$icon"/></span>
            <span class="login-profile-label">{{ $role->label() }}</span>
            <span class="login-profile-arrow"><x-icon name="arrow-right"/></span>
        </a>
    @endforeach
</nav>
@endsection
