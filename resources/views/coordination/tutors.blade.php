@extends('layouts.coordination')
@section('title', 'Equipo de tutores')
@section('content')
<x-back-link/>
<div class="advisor-page-heading">
    <div>
        <span class="advisor-eyebrow">COORDINACIÓN GENERAL</span>
        <h1 class="portal-title">Nuestro equipo de tutores</h1>
        <p class="portal-subtitle">Conoce sus secciones, acompaña su trabajo y consulta su actividad.</p>
    </div>
    @can('viewAny', \App\Models\User::class)
        <a class="btn btn-orange" href="{{ route('users.index', ['role' => 'tutor']) }}"><x-icon name="person"/> Administrar tutores</a>
    @endcan
</div>
@include('coordination.filters', ['directory' => true])
<section class="portal-panel"><div class="panel-title-row"><div class="portal-panel__heading"><x-icon name="people"/><div><h2>{{ $tutorRows->total() }} tutores encontrados</h2><p>Actividad en el intervalo y pendientes actuales del año seleccionado.</p></div></div><a class="advisor-text-link" href="{{ route('coordination.assignments', ['school_year_id' => $filters['school_year_id']]) }}">Administrar secciones <x-icon name="arrow-right"/></a></div>@include('coordination.tutor-table')<div class="mt-3">{{ $tutorRows->links() }}</div></section>
<p class="soft-note"><x-icon name="info-circle"/>Las sesiones corresponden a registros de asistencia iniciados por el tutor. Los pendientes pertenecen a sus secciones asignadas; no son una medida automática de desempeño.</p>
@endsection
