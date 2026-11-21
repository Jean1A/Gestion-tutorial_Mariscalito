@extends('layouts.app')
@section('title', $canPublish ? 'Sala de cómputo' : 'Buzón derivado')
@section('content')
<x-back-link/>
<h1>{{ $canPublish ? 'Sala de cómputo' : 'Buzón derivado' }}</h1>
<p class="portal-subtitle">{{ $canPublish ? 'Publica una actividad para el horario de tutoría de tu sección.' : 'Solicitudes derivadas por los tutores para atención confidencial.' }}</p>
@if($canPublish)
    <section class="portal-panel">
        <div class="portal-panel__heading"><x-icon name="calendar-week"/><h2>Publicar actividad</h2></div>
        @if($sessions->isEmpty())
            <p class="soft-note">Primero debe haber una sesión grupal vigente en una sección a tu cargo.</p>
            @can('advisor-portal')<a class="btn btn-outline-primary" href="{{ route('support.sessions') }}">Ir a tutoría grupal</a>@endcan
        @else
            <form method="post" action="{{ route('student-tutoring.staff.publish') }}">
                @csrf
                <div class="row g-3">
                    <div class="col-md-6"><label for="session_id" class="form-label">Sesión grupal</label><select id="session_id" name="session_id" class="form-select" required>
                        <option value="">Selecciona la sesión</option>
                        @foreach($sessions as $session)<option value="{{ $session->id }}" @selected((int) old('session_id') === $session->id)>{{ $session->scheduled_on->format('d/m/Y') }} · {{ $session->section->grade->name }} {{ $session->section->name }} · {{ $session->topic }}</option>@endforeach
                    </select></div>
                    <div class="col-md-6"><label for="instrument_phase" class="form-label">Actividad</label><select id="instrument_phase" name="instrument_phase" class="form-select" required>
                        <option value="">Selecciona la actividad</option>
                        @foreach($instruments as $key => $instrument)
                            @foreach($instrument['phases'] as $phase)
                                <option value="{{ $key }}:{{ $phase }}" @selected(old('instrument_phase') === $key.':'.$phase)>{{ $instrument['title'] }} · {{ config('student_tutoring.phases.'.$phase) }}</option>
                            @endforeach
                        @endforeach
                    </select></div>
                    <div class="col-sm-6"><label for="opens" class="form-label">Hora de apertura</label><input id="opens" name="opens" type="time" class="form-control" value="{{ old('opens') }}" required></div>
                    <div class="col-sm-6"><label for="closes" class="form-label">Hora de cierre</label><input id="closes" name="closes" type="time" class="form-control" value="{{ old('closes') }}" required></div>
                    <div class="col-12"><label for="prompt" class="form-label">Caso y pregunta (solo para el foro)</label><textarea id="prompt" name="prompt" class="form-control" rows="3" maxlength="3000">{{ old('prompt') }}</textarea><div class="form-text">Usa un caso ficticio o general, sin datos personales.</div></div>
                    <div class="col-12"><button class="btn btn-orange">Publicar para la sección</button></div>
                </div>
            </form>
        @endif
    </section>
    <section class="portal-panel">
        <h2 class="h5">Actividades publicadas</h2>
        <div class="table-responsive"><table class="portal-table">
            <thead><tr><th>Actividad</th><th>Sesión</th><th>Horario</th><th>Acción</th></tr></thead>
            <tbody>@forelse($activities as $activity)
                <tr><td>{{ config('student_tutoring.instruments.'.$activity->instrument.'.title') }}<br><small>{{ config('student_tutoring.phases.'.$activity->phase) }}</small></td>
                    <td>{{ $activity->topic }}</td><td>{{ \Illuminate\Support\Carbon::parse($activity->opens_at)->format('d/m H:i') }}–{{ \Illuminate\Support\Carbon::parse($activity->closes_at)->format('H:i') }}</td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('student-tutoring.staff.activity', $activity->id) }}">Abrir</a></td></tr>
            @empty
                <tr><td colspan="4">Aún no hay actividades publicadas.</td></tr>
            @endforelse</tbody>
        </table></div>
        @if($activities->hasPages())<div class="mt-3">{{ $activities->links() }}</div>@endif
    </section>
@endif
@if($role !== \App\Enums\RoleName::Teacher)
    <section class="portal-panel">
        <div class="portal-panel__heading"><x-icon name="lock"/><h2>Seguimiento confidencial</h2></div>
        <div class="table-responsive"><table class="portal-table">
            <thead><tr><th>Estudiante</th><th>Espacio</th><th>Estado</th><th>Acción</th></tr></thead>
            <tbody>@forelse($entries as $entry)
                <tr><td>{{ $entry->last_name }}, {{ $entry->first_name }}</td>
                    <td>{{ config('student_tutoring.entry_labels.'.$entry->kind) }}@if($entry->category)<br><small>{{ config('student_tutoring.request_categories.'.$entry->category) }}</small>@endif</td>
                    <td><span class="status-pill">{{ $entry->attended_at ? 'Respondida' : ($entry->referred_at ? 'Derivada' : 'Pendiente') }}</span></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('student-tutoring.staff.entry', $entry->id) }}">Revisar</a></td></tr>
            @empty
                <tr><td colspan="4">No tienes registros confidenciales asignados.</td></tr>
            @endforelse</tbody>
        </table></div>
        @if($entries->hasPages())<div class="mt-3">{{ $entries->links() }}</div>@endif
    </section>
@endif
@endsection
