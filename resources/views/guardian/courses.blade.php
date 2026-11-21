@extends('layouts.guardian')
@section('title','Cursos')
@section('content')
<x-back-link/>
<h1>Cursos</h1><p class="portal-subtitle">Consulta los contenidos y comunícate con los docentes.</p><x-child-banner :child="$child" :enrollment="$enrollment"/>
<div class="courses-grid">@forelse($courses as $offering)<article class="course-card"><div class="course-card__header"><div class="course-symbol"><x-icon :name="$offering->course->icon"/></div><div><h2>{{ $offering->course->name }}</h2><p>{{ $offering->section->schoolYear->year }} · {{ $offering->section->grade->name }} {{ $offering->section->name }}</p><p>Docente<strong>{{ $offering->teacher->name }}</strong></p></div></div><a class="btn btn-orange" href="{{ route('portal.course',[$child,$offering]) }}"><x-icon name="book"/>Ver contenido</a><div class="course-contact">@if($offering->teacher->phone && $offering->teacher->is_active)<a class="btn btn-outline-primary" href="tel:{{ $offering->teacher->phone }}"><x-icon name="telephone"/>Llamar</a>@endif @if($offering->teacher->email && $offering->teacher->is_active)<a class="btn btn-outline-primary" href="mailto:{{ $offering->teacher->email }}"><x-icon name="envelope"/>Correo</a>@endif</div></article>@empty<div class="empty-state"><x-icon name="book"/><h2>Cursos por asignar</h2><p class="mb-0">El colegio publicará aquí los cursos de la sección.</p></div>@endforelse</div>
@endsection
