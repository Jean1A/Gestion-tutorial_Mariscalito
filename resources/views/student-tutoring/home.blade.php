@extends('layouts.student')
@section('title', 'Sala de cómputo')
@section('content')
<div class="advisor-page-heading">
    <div><h1>Sala de cómputo</h1><p class="portal-subtitle">Entra a las actividades que habilitó tu tutoría.</p></div>
    <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-outline-primary"><x-icon name="box-arrow-right"/> Cerrar sesión</button></form>
</div>
@if(!$enrollment)
    <p class="soft-note">Tu cuenta requiere una matrícula activa para abrir la sala. Consulta con tu tutor.</p>
@else
    <section class="portal-panel">
        <div class="portal-panel__heading"><x-icon name="calendar-week"/><div><h2>Actividades de tutoría</h2><p>Hora del colegio. Tus actividades se abren durante el horario indicado.</p></div></div>
        <div class="table-responsive">
            <table class="portal-table">
                <thead><tr><th scope="col">Actividad</th><th scope="col">Horario</th><th scope="col">Estado</th></tr></thead>
                <tbody>
                @forelse($activities as $activity)
                    @php
                        $instrument = $tutoring->instrument($activity);
                        $answered = $completed->contains($activity->id);
                        $open = $tutoring->isOpen($activity);
                        $started = $activity->opens_at <= now()->toDateTimeString();
                    @endphp
                    <tr>
                        <td><strong>{{ $instrument['title'] }}</strong><br><small>{{ $instrument['dimension'] }} · {{ config('student_tutoring.phases.'.$activity->phase) }}</small></td>
                        <td>{{ \Illuminate\Support\Carbon::parse($activity->opens_at)->format('d/m H:i') }}–{{ \Illuminate\Support\Carbon::parse($activity->closes_at)->format('H:i') }}</td>
                        <td>
                            @if($answered || $open || ($activity->instrument === 'forum' && $started))
                                <a href="{{ route('student-tutoring.activity', $activity->id) }}" class="btn btn-sm {{ $open && !$answered ? 'btn-orange' : 'btn-outline-primary' }}">{{ $answered ? 'Ver envío' : ($open ? 'Empezar' : 'Ver caso') }}</a>
                            @else
                                <span class="status-pill status-pill--muted">{{ $started ? 'Cerrada' : 'Programada' }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3">Todavía no hay actividades habilitadas para ti.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($activities->hasPages())<div class="mt-3">{{ $activities->links() }}</div>@endif
    </section>
    <section class="portal-panel">
        <h2 class="h5 mb-3">Mis espacios</h2>
        <div class="course-shortcuts">
            <a href="{{ route('student-tutoring.entries', 'journal') }}"><x-icon name="book"/> Bitácora personal</a>
            <a href="{{ route('student-tutoring.entries', 'request') }}"><x-icon name="chat-dots"/> Pedir orientación</a>
            <a href="{{ route('student-tutoring.entries', 'goal') }}"><x-icon name="mortarboard"/> Mis metas de estudio</a>
        </div>
    </section>
@endif
<p class="soft-note"><x-icon name="info-circle"/> Antes de dejar la computadora, cierra tu sesión.</p>
@endsection
