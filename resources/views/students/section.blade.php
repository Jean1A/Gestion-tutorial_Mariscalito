@extends(auth()->user()->activeRole()===\App\Enums\RoleName::Tutor ? 'layouts.advisor':'layouts.app')
@section('title','Estudiantes de la sección')
@section('content')
@if(auth()->user()->activeRole() === \App\Enums\RoleName::Tutor)
    <x-back-link :href="route('advisor.sections')">Volver a mis secciones</x-back-link>
@elseif(auth()->user()->activeRole() === \App\Enums\RoleName::Assistant)
    <x-back-link :href="route('dashboard').'#authorized-sections'">Volver a mis secciones</x-back-link>
@else
    <x-back-link/>
@endif
<div class="advisor-page-heading"><div><h1>{{ $section->grade->name }} · Sección {{ $section->name }}</h1><p class="portal-subtitle">Año escolar {{ $section->schoolYear->year }} · Estudiantes con matrícula activa</p></div></div>
@if(auth()->user()->permits('attendance.view'))<a href="{{ route('attendance.edit', $section) }}" class="btn btn-outline-primary mb-3">Asistencia diaria</a>@endif
@if(isset($courses) && auth()->user()->permits('courses.record'))<section class="portal-panel"><div class="portal-panel__heading"><x-icon name="book"/><h2>Asistencia por curso</h2></div><div class="course-shortcuts">@forelse($courses as $offering)<a href="{{ route('courses.record',$offering) }}"><x-icon :name="$offering->course->icon"/>{{ $offering->course->name }}<x-icon name="chevron-right"/></a>@empty<p class="tutor-name">El colegio todavía no ha asignado cursos a esta sección.</p>@endforelse</div></section>@endif
<section class="portal-panel"><div class="portal-panel__heading"><x-icon name="people"/><h2>Mis estudiantes</h2></div>@forelse($students as $student)<article class="advisor-student-row"><img class="student-avatar" src="{{ asset('brand/student.svg') }}" alt="" width="48" height="48"><div><strong>{{ $student->full_name }}</strong><small>DNI {{ $student->dni }}</small></div><a class="btn btn-outline-primary" href="{{ auth()->user()->activeRole()===\App\Enums\RoleName::Tutor ? route('advisor.student',$student):route('students.show',$student) }}">Ver seguimiento <x-icon name="arrow-right"/></a></article>@empty<div class="empty-state"><h2>No hay matrículas activas</h2><p class="mb-0">Los estudiantes aparecerán cuando el colegio registre sus matrículas.</p></div>@endforelse<div class="mt-3">{{ $students->links() }}</div></section>
@endsection
