@extends('layouts.app')
@section('title', 'Inicio · '.$role->label())
@section('content')
<h1>Inicio · {{ $role->label() }}</h1>
<p class="portal-subtitle">Consulta tus secciones y las tareas de acompañamiento escolar.</p>

<section class="portal-panel">
    <div class="portal-panel__heading"><x-icon name="activity"/><div><h2>Accesos rápidos</h2><p>Ve a los módulos disponibles para tu función.</p></div></div>
    <div class="course-shortcuts">
        @if(app(\App\Services\StudentSupportService::class)->canReport(auth()->user()))
            <a href="{{ route('support.index') }}"><x-icon name="file-earmark-text"/> Acompañamiento escolar</a>
        @endif
        @if(auth()->user()->permits('courses.record'))
            <a href="{{ route('courses.mine') }}"><x-icon name="book"/> Asistencia por curso</a>
        @endif
        @if(auth()->user()->permits('communications.send') || auth()->user()->permits('communications.respond'))
            <a href="{{ route('communications.index') }}"><x-icon name="envelope"/> Comunicaciones y citaciones</a>
        @endif
        <a href="{{ route('notifications.index') }}"><x-icon name="bell"/> Notificaciones</a>
    </div>
</section>

<section class="portal-panel" id="authorized-sections">
    <div class="portal-panel__heading"><x-icon name="people"/><div><h2>Secciones autorizadas</h2><p>Consulta la lista de estudiantes y la asistencia diaria.</p></div></div>
    <div class="portal-section-list">
        @forelse($sections as $section)
            <article class="portal-section-item">
                <span class="grade-badge">{{ $section->schoolYear->year }} · {{ $section->grade->educationLevel->name }}</span>
                <h3>{{ $section->grade->name }} · Sección {{ $section->name }}</h3>
                <div class="portal-section-actions">
                    @can('recordAttendance', $section)
                        <a href="{{ route('attendance.edit', $section) }}" class="btn btn-orange"><x-icon name="check-circle"/> Registrar asistencia diaria</a>
                    @endcan
                    <a href="{{ route('sections.show', $section) }}" class="btn btn-outline-primary">Ver estudiantes</a>
                </div>
            </article>
        @empty
            <div class="empty-state"><x-icon name="people"/><p class="mb-0">No hay secciones autorizadas para esta función.</p></div>
        @endforelse
    </div>
    @if($sections->hasPages())<div class="mt-3">{{ $sections->links() }}</div>@endif
</section>

@if($alerts->isNotEmpty())
    <section class="portal-panel">
        <div class="portal-panel__heading"><x-icon name="bell"/><h2>Alertas de inasistencia pendientes</h2></div>
        <div class="list-group">
            @foreach($alerts as $alert)
                <a class="list-group-item list-group-item-action" href="{{ route('students.show', $alert->enrollment->student) }}">{{ $alert->enrollment->student->full_name }} · {{ $alert->type === 'percentage' ? $alert->observed_value.' % de faltas injustificadas' : $alert->observed_value.' días lectivos consecutivos' }}</a>
            @endforeach
        </div>
    </section>
@endif
@endsection
