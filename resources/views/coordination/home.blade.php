@extends('layouts.coordination')
@section('title', $profileTutor ? 'Seguimiento del tutor' : 'Panel de coordinación')
@section('content')
@php($scopeParams = array_filter(\Illuminate\Support\Arr::only($filters, ['school_year_id', 'tutor_id', 'section_id', 'from', 'to'])))
<section class="advisor-hero coordination-hero">
    <div><span class="advisor-eyebrow">{{ $profileTutor ? 'SEGUIMIENTO INDIVIDUAL' : 'PORTAL DE COORDINACIÓN' }}</span><h1>{{ $profileTutor ? $profileTutor->name : (auth()->user()->activeRole() === \App\Enums\RoleName::Coordinator ? 'Panel general' : 'Un equipo, un mismo propósito.') }}</h1><p>{{ $profileTutor ? 'Asignaciones y actividades del tutor en un solo lugar.' : 'Acompaña a tus tutores y sigue de cerca el bienestar de cada sección.' }}</p>
        <div class="advisor-hero-actions">@if($profileTutor)<a href="{{ route('coordination.tutors') }}" class="btn btn-outline-primary"><x-icon name="arrow-left"/>Volver al equipo</a>@else<a href="{{ route('coordination.assignments') }}" class="btn btn-orange"><x-icon name="people"/>Gestionar asignaciones</a><a href="{{ route('coordination.tutors') }}" class="btn btn-outline-primary">Ver equipo <x-icon name="arrow-right"/></a>@endif</div>
    </div>
    <div class="coordination-hero-mark" aria-hidden="true"><span><x-icon name="people"/></span><i><x-icon name="check-circle"/></i><b>JUNTOS ACOMPAÑAMOS MEJOR</b></div>
</section>
@if(!$profileTutor && auth()->user()->activeRole() === \App\Enums\RoleName::Coordinator)
    <section class="portal-panel">
        <div class="portal-panel__heading"><x-icon name="activity"/><h2>Accesos rápidos</h2></div>
        <div class="course-shortcuts">
            @can('viewAny', \App\Models\User::class)<a href="{{ route('users.index') }}"><x-icon name="person"/> Gestionar usuarios</a>@endcan
            @can('manage-academics')<a href="{{ route('academics.index', 'years') }}"><x-icon name="calendar-week"/> Administrar año escolar</a>@endcan
            <a href="{{ route('coordination.follow-up') }}"><x-icon name="bell"/> Ver seguimientos y alertas</a>
            @if(auth()->user()->permits('communications.send'))<a href="{{ route('communications.index') }}"><x-icon name="envelope"/> Comunicaciones</a>@endif
        </div>
    </section>
@endif
@if($profileTutor)
    <div class="coordination-profile-contact"><span class="status-pill {{ $profileTutor->is_active ? 'status-pill--success' : 'status-pill--muted' }}">Cuenta {{ $profileTutor->is_active ? 'activa' : 'inactiva' }}</span><span><x-icon name="envelope"/>{{ $profileTutor->email ?: 'Sin correo registrado' }}</span><span><x-icon name="telephone"/>{{ $profileTutor->phone ?: 'Sin teléfono registrado' }}</span>@can('update', $profileTutor)<a class="portal-action" href="{{ route('users.edit', $profileTutor) }}">Editar cuenta</a>@endcan</div>
@endif
@include('coordination.filters', ['filterAction' => $profileTutor ? route('coordination.tutor', $profileTutor) : route('coordination.index')])
<div class="coordination-section-heading"><h2>Una mirada al equipo</h2><span>Asignaciones y pendientes actuales del año seleccionado</span></div>
<div class="advisor-stats">
    <a href="{{ route('coordination.tutors', $scopeParams) }}" class="advisor-stat"><span class="advisor-stat-icon"><x-icon name="people"/></span><div><strong>{{ $stats['tutors'] }}</strong><span>Tutores activos</span></div></a>
    <a href="{{ route('coordination.assignments', $scopeParams) }}" class="advisor-stat"><span class="advisor-stat-icon"><x-icon name="mortarboard"/></span><div><strong>{{ $stats['assigned_sections'] }}<small class="coordination-stat-total"> / {{ $stats['sections'] }}</small></strong><span>Secciones con tutor</span></div></a>
    <div class="advisor-stat"><span class="advisor-stat-icon"><x-icon name="person"/></span><div><strong>{{ $stats['students'] }}</strong><span>Estudiantes en las secciones</span></div></div>
    <a href="{{ route('coordination.follow-up', $scopeParams) }}" class="advisor-stat advisor-stat--orange"><span class="advisor-stat-icon"><x-icon name="file-earmark-text"/></span><div><strong>{{ $stats['pending'] }}</strong><span>Justificaciones pendientes</span></div></a>
</div>
    <section class="portal-panel"><h2>Acompañamiento e intervenciones</h2><p>{{ $stats['open_interventions'] }} intervenciones abiertas · {{ $stats['open_cases'] }} casos psicológicos abiertos · {{ $stats['overdue_interventions'] }} intervenciones vencidas.</p><p>{{ $stats['completed_tutoring'] }} de {{ $stats['scheduled_tutoring'] }} sesiones grupales realizadas en el intervalo.@if($stats['scheduled_tutoring']) {{ round($stats['completed_tutoring'] * 100 / $stats['scheduled_tutoring'], 1) }} % de ejecución.@endif</p><div class="course-shortcuts"><a href="{{ route('support.index') }}">Ver intervenciones y acuerdos</a><a href="{{ route('school-reports.index',['type'=>'risk','school_year_id'=>$filters['school_year_id']]) }}">Semáforo integral y reportes</a><a href="{{ route('support.sessions') }}">Sesiones de tutoría grupal</a></div></section>
    <div class="coordination-dashboard-grid">
    <section class="portal-panel"><div class="panel-title-row"><div class="portal-panel__heading"><x-icon name="bar-chart"/><div><h2>El acompañamiento en cifras</h2><p>Actividad durante el intervalo seleccionado.</p></div></div><a href="{{ route('coordination.activities', $scopeParams) }}" class="advisor-text-link">Ver detalle <x-icon name="arrow-right"/></a></div>
        <div class="coordination-mini-stats"><div><strong>{{ $stats['sessions'] }}</strong><span>Sesiones de asistencia</span></div><div><strong>{{ $stats['reviewed'] }}</strong><span>Justificaciones revisadas</span></div><div><strong>{{ $stats['appointments'] }}</strong><span>Citaciones enviadas</span></div></div>
        <div class="coordination-chart-heading"><strong>Sesiones por fecha</strong><span>Últimas 10 fechas con registro</span></div>
        @if($trend->isNotEmpty())
            <div class="coordination-bar-chart" role="img" aria-label="Sesiones de asistencia por fecha: {{ $trend->map(fn($point) => \Carbon\Carbon::parse($point->date)->format('d/m').' '.$point->total.' sesiones')->implode('; ') }}">
                @foreach($trend as $point)<div class="coordination-bar-column"><strong>{{ $point->total }}</strong><div class="coordination-bar-track"><span style="height:{{ max(3, round($point->total / max(1, $trend->max('total')) * 100)) }}%"></span></div><small>{{ \Carbon\Carbon::parse($point->date)->format('d/m') }}</small></div>@endforeach
            </div>
        @else<div class="advisor-calm-state"><x-icon name="bar-chart"/><strong>Aún no hay sesiones en este intervalo</strong><p>Los gráficos se construyen con la asistencia registrada.</p></div>@endif
    </section>
    <section class="portal-panel"><div class="portal-panel__heading"><x-icon name="check-circle"/><div><h2>Asistencia de las secciones</h2><p>Registros de estudiante y curso en el intervalo.</p></div></div>
        @php($attendedPercentage = $stats['registered'] ? round(($stats['present'] + $stats['late']) * 100 / $stats['registered']) : 0)
        <div class="coordination-attendance-summary"><div class="coordination-donut {{ !$stats['registered'] ? 'is-empty' : '' }}" style="--attendance:{{ $attendedPercentage }}%"><div><strong>{{ $stats['registered'] ? $attendedPercentage.'%' : '—' }}</strong><span>Presente o tardanza</span></div></div><div class="coordination-legend">@foreach([['present', 'Presente', 'blue'], ['late', 'Tardanza', 'sky'], ['justified', 'Falta justificada', 'sand'], ['unjustified', 'Falta injustificada', 'orange']] as [$key, $label, $color])<div><i class="coordination-dot coordination-dot--{{ $color }}" aria-hidden="true"></i><span>{{ $label }}</span><strong>{{ $stats[$key] }}</strong></div>@endforeach</div></div>
        <p class="stats-caption">{{ $stats['registered'] }} registros. Una persona puede figurar en varios cursos. Faltas injustificadas: {{ $stats['absence_percentage'] }} %. Los días sin registro no se cuentan.</p>
    </section>
</div>
<section class="portal-panel"><div class="panel-title-row"><div class="portal-panel__heading"><x-icon name="people"/><div><h2>{{ $profileTutor ? 'Actividad del tutor' : 'Así avanza el equipo' }}</h2><p>Acciones realizadas por cada tutor y pendientes de sus secciones actuales.</p></div></div><a href="{{ route('coordination.tutors', $scopeParams) }}" class="advisor-text-link">Ver todos <x-icon name="arrow-right"/></a></div>@include('coordination.tutor-table')<p class="stats-caption">Las sesiones se atribuyen a quien inició el registro; las revisiones y envíos, a quien los realizó. No constituyen una calificación de desempeño.</p></section>
<div class="coordination-dashboard-grid coordination-dashboard-grid--bottom">
    <section class="portal-panel"><div class="panel-title-row"><div class="portal-panel__heading"><x-icon name="activity"/><div><h2>Actividad reciente</h2><p>Acciones registradas por los tutores.</p></div></div><a href="{{ route('coordination.activities', $scopeParams) }}" class="advisor-text-link">Ver historial</a></div>@include('coordination.timeline')</section>
    <section class="portal-panel"><div class="portal-panel__heading"><x-icon name="bell"/><div><h2>Para tener presente</h2><p>Cobertura y seguimiento actual del año.</p></div></div><div class="coordination-coverage"><div><strong>Cobertura de tutoría</strong><span>{{ $stats['coverage'] }} %</span></div><div class="progress" role="progressbar" aria-label="Secciones con tutor activo" aria-valuenow="{{ $stats['coverage'] }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:{{ $stats['coverage'] }}%"></div></div><p>{{ $stats['assigned_sections'] }} de {{ $stats['sections'] }} secciones activas tienen tutor.</p></div>
        @foreach($unassigned as $unassignedSection)<a class="coordination-attention" href="{{ route('coordination.assignments', ['school_year_id' => $filters['school_year_id'], 'section_id' => $unassignedSection->id]) }}"><x-icon name="people"/><span>{{ $unassignedSection->grade->name }} {{ $unassignedSection->name }}<small>Sección sin tutor activo</small></span><x-icon name="chevron-right"/></a>@endforeach
        <a class="coordination-attention" href="{{ route('coordination.follow-up', $scopeParams) }}"><x-icon name="bell"/><span>{{ $stats['risk_students'] }} estudiantes con alertas activas<small>Revisa el contexto con su tutor.</small></span><x-icon name="chevron-right"/></a>
    </section>
</div>
@endsection
