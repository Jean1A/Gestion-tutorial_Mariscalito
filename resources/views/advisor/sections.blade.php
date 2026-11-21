@extends('layouts.advisor')
@section('title','Mis secciones')
@section('content')
<x-back-link/>
<h1>Mis secciones</h1><p class="portal-subtitle">Acompaña a los grupos que el colegio te asignó.</p><div class="courses-grid">@forelse($sections as $section)<article class="course-card advisor-section-card"><div class="course-card__header"><div class="course-symbol"><x-icon name="people"/></div><div><h2>{{ $section->grade->name }} · {{ $section->name }}</h2><p>{{ $section->grade->educationLevel->name }} · {{ $section->schoolYear->year }}</p></div></div><p class="advisor-section-total"><strong>{{ $section->enrollments_count }}</strong> estudiantes con matrícula activa</p><a class="btn btn-orange" href="{{ route('sections.show',$section) }}">Ver sección <x-icon name="arrow-right"/></a><a class="btn btn-outline-primary w-100 mt-2" href="{{ route('advisor.students',['section_id'=>$section->id]) }}">Lista de estudiantes</a></article>@empty<div class="empty-state"><x-icon name="people"/><h2>Secciones por asignar</h2><p class="mb-0">Solicita al administrador que revise tus asignaciones.</p></div>@endforelse</div><div class="mt-3">{{ $sections->links() }}</div>
@endsection
