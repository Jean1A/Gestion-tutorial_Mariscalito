@extends('layouts.app')
@section('title', 'Gestión de usuarios')
@section('content')
<x-back-link/>
<div class="advisor-page-heading">
    <div>
        <h1>Gestión de usuarios</h1>
        <p class="portal-subtitle">Elige el grupo que quieres administrar.</p>
    </div>
</div>

<div class="account-role-grid">
    @foreach($roleLabels as $role => $label)
        <article class="account-role-card">
            <span class="course-symbol"><x-icon name="person"/></span>
            <h2>{{ $label }}</h2>
            <a href="{{ route('users.index', ['role' => $role]) }}" class="btn btn-orange" aria-label="Administrar {{ strtolower($label) }}">Administrar</a>
        </article>
    @endforeach
    @can('viewAny', \App\Models\Student::class)
        <article class="account-role-card account-role-card--last">
            <span class="course-symbol"><x-icon name="mortarboard"/></span>
            <h2>Estudiantes</h2>
            <a href="{{ route('students.index') }}" class="btn btn-orange" aria-label="Administrar estudiantes">Administrar</a>
        </article>
    @endcan
</div>
@endsection
