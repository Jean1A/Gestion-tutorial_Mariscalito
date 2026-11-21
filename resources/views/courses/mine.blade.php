@extends(auth()->user()->activeRole()===\App\Enums\RoleName::Tutor ? 'layouts.advisor':'layouts.app')
@section('title','Asistencia y cursos')
@section('content')
@if(auth()->user()->activeRole() !== \App\Enums\RoleName::Teacher)
    <x-back-link/>
@endif
<div class="advisor-page-heading"><div><h1>Asistencia y cursos</h1><p class="portal-subtitle">Selecciona un curso para registrar la asistencia de tus estudiantes.</p></div></div>
@if(auth()->user()->activeRole() === \App\Enums\RoleName::Teacher)<a class="btn btn-outline-primary mb-3" href="{{ route('support.index') }}">Mis incidencias reportadas</a>@endif
<div class="courses-grid">@forelse($courses as $offering)<article class="course-card"><div class="course-card__header"><span class="course-symbol"><x-icon :name="$offering->course->icon"/></span><div><h2>{{ $offering->course->name }}</h2><span class="grade-badge">{{ $offering->section->grade->name }} · Sección {{ $offering->section->name }}</span><p class="mt-2">Año escolar {{ $offering->section->schoolYear->year }}</p><strong>Docente: {{ $offering->teacher?->name ?? 'Por asignar' }}</strong></div></div><a class="btn btn-orange" href="{{ route('courses.record',$offering) }}"><x-icon name="check-circle"/>{{ auth()->user()->can("record", $offering) ? "Registrar asistencia" : "Consultar asistencia" }}</a>@can('grade',$offering)<p class="stats-caption"><x-icon name="file-earmark-text"/>También puedes publicar contenido y niveles de logro de este curso.</p>@else<p class="stats-caption">El docente asignado publica el contenido y los niveles de logro.</p>@endcan</article>@empty<div class="empty-state"><x-icon name="book"/><h2>Aún no hay cursos disponibles</h2><p class="mb-0">El colegio debe asignar cursos a tus secciones.</p></div>@endforelse</div>
@endsection
