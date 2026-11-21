@extends('layouts.coordination')
@section('title', 'Actividad y citaciones')
@section('content')
<x-back-link/>
<div class="advisor-page-heading"><div><span class="advisor-eyebrow">SEGUIMIENTO DEL EQUIPO</span><h1 class="portal-title">El trabajo de nuestros tutores</h1><p class="portal-subtitle">Asistencia, revisión de solicitudes y comunicación con las familias.</p></div></div>
@include('coordination.filters')
<div class="coordination-activity-grid">
    <section class="portal-panel"><div class="portal-panel__heading"><x-icon name="activity"/><div><h2>Historial de actividad</h2><p>Acciones realizadas dentro del intervalo seleccionado.</p></div></div>@include('coordination.timeline'){{ $activities->links() }}</section>
    <div>
        <section class="portal-panel"><div class="portal-panel__heading"><x-icon name="book"/><div><h2>Sesiones de asistencia</h2><p>Por fecha lectiva; el responsable es quien inició la sesión.</p></div></div>
            @forelse($sessions as $session)<article class="coordination-session"><div><strong>{{ $session->offering->course->name }}</strong><span class="status-pill status-pill--info">{{ $session->records_count }} registros</span></div><p>{{ $session->offering->section->grade->name }} {{ $session->offering->section->name }} · {{ $session->schoolDay->date->format('d/m/Y') }}</p><small>{{ $session->recorder?->name ?? 'Cuenta no disponible' }}</small></article>@empty<div class="advisor-calm-state"><x-icon name="book"/><strong>No hay sesiones en el intervalo</strong></div>@endforelse
            <div class="mt-3">{{ $sessions->links() }}</div>
        </section>
        <section class="portal-panel"><div class="portal-panel__heading"><x-icon name="calendar-week"/><div><h2>Citaciones y comunicados</h2><p>Envíos de los tutores durante el intervalo.</p></div></div>
            @forelse($communications as $communication)<article class="coordination-session"><div><strong>{{ $communication->title }}</strong><span class="status-pill {{ $communication->type === 'appointment' ? '' : 'status-pill--info' }}">{{ $communication->type === 'appointment' ? 'Citación' : 'Comunicado' }}</span></div><p>{{ $communication->author?->name ?? 'Cuenta no disponible' }} · Enviado {{ $communication->created_at->format('d/m/Y') }}</p>@if($communication->scheduled_at)<p>Cita: {{ $communication->scheduled_at->format('d/m/Y H:i') }}</p>@endif<small>{{ $communication->recipients_count }} destinatarios · {{ $communication->read_count }} lecturas · {{ $communication->responses_count }} respuestas</small></article>@empty<div class="advisor-calm-state"><x-icon name="calendar-week"/><strong>Sin envíos en este intervalo</strong></div>@endforelse
            <div class="mt-3">{{ $communications->links() }}</div>
        </section>
    </div>
</div>
@endsection
