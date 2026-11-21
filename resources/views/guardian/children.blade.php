@extends('layouts.guest')
@section('title','Selecciona a tu hijo')
@section('panel-class','auth-panel--children')
@section('content')
<div class="children-heading"><h1>Selecciona a tu hijo</h1><p>Elige un perfil para consultar su información escolar.</p></div>
@forelse($children as $child)
    @php($enrollment=app(\App\Services\GuardianPortalService::class)->enrollment($child))
    <form method="post" action="{{ route('portal.children.select',$child) }}">@csrf<button class="child-choice" type="submit"><img class="student-avatar" src="{{ asset('brand/student.svg') }}" width="82" height="82" alt=""><span class="child-choice__name">{{ $child->full_name }}<small>Ver información escolar</small></span><span class="grade-badge">{{ $enrollment?->section->grade->name ?? 'Sin matrícula' }}</span><x-icon name="chevron-right"/></button></form>
@empty
    <div class="empty-state"><x-icon name="people"/><h2>Tus hijos aparecerán aquí</h2><p class="mb-0">Solicita al colegio que verifique tu vínculo con cada estudiante.</p></div>
@endforelse
@endsection
